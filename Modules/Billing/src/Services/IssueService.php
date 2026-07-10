<?php

namespace Modules\Billing\Services;

use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Modules\Audit\Services\AuditService;
use Modules\Billing\Models\Charge;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Models\InvoiceLine;
use Modules\Billing\Models\InvoiceSequence;
use Modules\Patients\Models\Patient;
use Modules\Platform\Exceptions\CrossTenantReferenceException;
use Modules\Platform\Exceptions\TenantContextMissingException;
use Modules\Platform\Models\User;
use Modules\Platform\Services\TenantContext;

class IssueService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditService $audit,
        private readonly InvoicePdfRenderer $pdfRenderer,
    ) {}

    /**
     * @param  iterable<int, Charge>  $charges
     */
    public function createDraftFromCharges(
        Patient $patient,
        iterable $charges,
        User $actor,
        string $payerType = Invoice::PAYER_SELF_PAY,
        ?string $payerName = null,
        ?CarbonInterface $issueDate = null,
        ?CarbonInterface $dueDate = null,
    ): Invoice {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($patient, 'patient_id');
        $this->assertPayerType($payerType);

        $charges = collect($charges)->values();

        if ($charges->isEmpty()) {
            throw new InvalidArgumentException('An invoice draft requires at least one charge.');
        }

        $charges->each(function (Charge $charge) use ($patient): void {
            $this->assertSameTenant($charge, 'charge_id');

            if ($charge->patient_id !== $patient->id) {
                throw CrossTenantReferenceException::forAttribute('charge_id', $charge->id);
            }

            if ($charge->status !== Charge::STATUS_VALIDATED) {
                throw new InvalidArgumentException('Only validated charges can be drafted for invoice issue.');
            }
        });

        return DB::transaction(function () use ($patient, $charges, $actor, $payerType, $payerName, $issueDate, $dueDate): Invoice {
            $invoice = Invoice::query()->create([
                'patient_id' => $patient->id,
                'payer_type' => $payerType,
                'payer_name' => $payerName,
                'series' => Invoice::SERIES_INVOICE,
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => $this->date($issueDate ?? now()),
                'due_date' => $this->date($dueDate ?? now()->addDays(14)),
                'currency' => $this->tenantCurrencyFromCharges($charges),
            ]);

            $charges->each(fn (Charge $charge) => $this->copyChargeLine($invoice, $charge));
            $this->recalculateDraftTotals($invoice);
            $invoice->balance()->create([
                'status' => Invoice::STATUS_DRAFT,
                'open_balance_minor' => 0,
            ]);

            $this->auditInvoice('invoice.drafted', $invoice->refresh(), $actor);

            return $invoice->refresh();
        });
    }

    public function issue(Invoice $draftInvoice, User $actor): Invoice
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($draftInvoice, 'invoice_id');

        if ($draftInvoice->status !== Invoice::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft invoices can be issued.');
        }

        return DB::transaction(function () use ($draftInvoice, $actor): Invoice {
            $invoice = Invoice::query()
                ->whereKey($draftInvoice->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($invoice->status !== Invoice::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only draft invoices can be issued.');
            }

            $lines = InvoiceLine::query()
                ->where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->get();

            if ($lines->isEmpty()) {
                throw new InvalidArgumentException('A draft invoice requires at least one line.');
            }

            $this->assertLinesCanIssue($invoice, $lines);
            $this->recalculateLineVat($lines);

            $subtotal = (int) $invoice->lines()->sum('line_total_minor');
            $vat = (int) $invoice->lines()->sum('line_vat_minor');
            $total = $subtotal + $vat;
            $number = $this->nextNumber($invoice->series);
            $pdfPath = $this->pdfRenderer->render($invoice->forceFill([
                'number' => (string) $number,
                'status' => Invoice::STATUS_ISSUED,
                'subtotal_minor' => $subtotal,
                'vat_total_minor' => $vat,
                'total_minor' => $total,
                'open_balance_minor' => $total,
            ]));

            $invoice->forceFill([
                'number' => (string) $number,
                'status' => Invoice::STATUS_ISSUED,
                'subtotal_minor' => $subtotal,
                'vat_total_minor' => $vat,
                'total_minor' => $total,
                'open_balance_minor' => $total,
                'pdf_path' => $pdfPath,
            ])->save();

            InvoiceLine::query()
                ->where('invoice_id', $invoice->id)
                ->whereNotNull('charge_id')
                ->get()
                ->each(function (InvoiceLine $line) use ($invoice): void {
                    Charge::query()
                        ->whereKey($line->charge_id)
                        ->update([
                            'status' => Charge::STATUS_INVOICED,
                            'invoice_id' => $invoice->id,
                            'updated_at' => now(),
                        ]);
                });

            $invoice->balance()->updateOrCreate(
                ['invoice_id' => $invoice->id],
                [
                    'status' => Invoice::STATUS_ISSUED,
                    'open_balance_minor' => $total,
                ],
            );

            $this->auditInvoice('invoice.issued', $invoice->refresh(), $actor, [
                'number' => $invoice->series.'-'.$invoice->number,
            ]);

            return $invoice->refresh();
        }, 5);
    }

    /**
     * @param  list<array{invoice_line_id: string, quantity?: int}>|null  $lines
     */
    public function creditNote(Invoice $invoice, ?array $lines, string $reason, User $actor): Invoice
    {
        $this->authorize($actor);
        $this->assertActorTenant($actor);
        $this->assertSameTenant($invoice, 'invoice_id');

        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Credit notes require a reason.');
        }

        if ($invoice->status !== Invoice::STATUS_ISSUED) {
            throw new InvalidArgumentException('Only issued invoices can be credited.');
        }

        return DB::transaction(function () use ($invoice, $lines, $reason, $actor): Invoice {
            $sourceInvoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $sourceLines = $this->creditSourceLines($sourceInvoice, $lines);

            $creditNote = Invoice::query()->create([
                'patient_id' => $sourceInvoice->patient_id,
                'payer_type' => $sourceInvoice->payer_type,
                'payer_name' => $sourceInvoice->payer_name,
                'series' => Invoice::SERIES_CREDIT_NOTE,
                'status' => Invoice::STATUS_DRAFT,
                'issue_date' => now()->toDateString(),
                'due_date' => now()->toDateString(),
                'currency' => $sourceInvoice->currency,
                'credit_note_for_invoice_id' => $sourceInvoice->id,
            ]);

            foreach ($sourceLines as $sourceLine) {
                /** @var InvoiceLine $line */
                $line = $sourceLine['line'];
                $quantity = $sourceLine['quantity'];

                InvoiceLine::query()->create([
                    'invoice_id' => $creditNote->id,
                    'charge_id' => null,
                    'original_invoice_line_id' => $line->id,
                    'code' => $line->code,
                    'description' => 'Credit: '.$line->description.' ('.$reason.')',
                    'quantity' => -1 * $quantity,
                    'unit_price_minor' => $line->unit_price_minor,
                    'vat_rate_bp' => $line->vat_rate_bp,
                    'line_total_minor' => -1 * $quantity * $line->unit_price_minor,
                    'line_vat_minor' => $this->vatMinor(-1 * $quantity * $line->unit_price_minor, $line->vat_rate_bp),
                ]);
            }

            $creditNote->balance()->create([
                'status' => Invoice::STATUS_DRAFT,
                'open_balance_minor' => 0,
            ]);

            $issuedCreditNote = $this->issue($creditNote->refresh(), $actor);

            $creditedGross = abs($issuedCreditNote->total_minor);
            if ($creditedGross >= $sourceInvoice->total_minor) {
                $sourceInvoice->balance()->updateOrCreate(
                    ['invoice_id' => $sourceInvoice->id],
                    [
                        'status' => Invoice::STATUS_CANCELLED_BY_CREDIT_NOTE,
                        'open_balance_minor' => 0,
                    ],
                );
            }

            $this->auditInvoice('invoice.credit_note_created', $issuedCreditNote, $actor, [
                'reason' => $reason,
                'original_invoice_id' => $sourceInvoice->id,
            ]);

            return $issuedCreditNote;
        }, 5);
    }

    public function vatMinor(int $lineTotalMinor, int $vatRateBp): int
    {
        $absolute = abs($lineTotalMinor);
        $rounded = intdiv($absolute * $vatRateBp + 5000, 10000);

        return $lineTotalMinor < 0 ? -1 * $rounded : $rounded;
    }

    private function copyChargeLine(Invoice $invoice, Charge $charge): InvoiceLine
    {
        return InvoiceLine::query()->create([
            'invoice_id' => $invoice->id,
            'charge_id' => $charge->id,
            'code' => $charge->code,
            'description' => $charge->description,
            'quantity' => $charge->quantity,
            'unit_price_minor' => $charge->unit_price_minor,
            'vat_rate_bp' => $charge->vat_rate_bp,
            'line_total_minor' => $charge->line_total_minor,
            'line_vat_minor' => $this->vatMinor($charge->line_total_minor, $charge->vat_rate_bp),
        ]);
    }

    private function recalculateDraftTotals(Invoice $invoice): void
    {
        $lines = $invoice->lines()->get();
        $subtotal = (int) $lines->sum('line_total_minor');
        $vat = (int) $lines->sum('line_vat_minor');

        $invoice->forceFill([
            'subtotal_minor' => $subtotal,
            'vat_total_minor' => $vat,
            'total_minor' => $subtotal + $vat,
            'open_balance_minor' => 0,
        ])->save();
    }

    /**
     * @param  EloquentCollection<int, InvoiceLine>  $lines
     */
    private function recalculateLineVat(EloquentCollection $lines): void
    {
        $lines->each(function (InvoiceLine $line): void {
            $line->forceFill([
                'line_vat_minor' => $this->vatMinor($line->line_total_minor, $line->vat_rate_bp),
            ])->save();
        });
    }

    /**
     * @param  EloquentCollection<int, InvoiceLine>  $lines
     */
    private function assertLinesCanIssue(Invoice $invoice, EloquentCollection $lines): void
    {
        $chargeIds = $lines->pluck('charge_id')->filter()->values();

        if ($invoice->credit_note_for_invoice_id !== null) {
            return;
        }

        if ($chargeIds->isEmpty()) {
            throw new InvalidArgumentException('A normal invoice requires charge-backed lines.');
        }

        $charges = Charge::query()->whereIn('id', $chargeIds)->lockForUpdate()->get();

        if ($charges->count() !== $chargeIds->count()) {
            throw new InvalidArgumentException('Every invoice line charge must exist before issue.');
        }

        $charges->each(function (Charge $charge) use ($invoice): void {
            if ($charge->patient_id !== $invoice->patient_id) {
                throw CrossTenantReferenceException::forAttribute('charge_id', $charge->id);
            }

            if ($charge->status !== Charge::STATUS_VALIDATED) {
                throw new InvalidArgumentException('All invoice charges must be validated before issue.');
            }
        });
    }

    /**
     * @param  list<array{invoice_line_id: string, quantity?: int}>|null  $requestedLines
     * @return Collection<int, array{line: InvoiceLine, quantity: int}>
     */
    private function creditSourceLines(Invoice $invoice, ?array $requestedLines): Collection
    {
        if ($requestedLines === null) {
            return InvoiceLine::query()
                ->where('invoice_id', $invoice->id)
                ->get()
                ->map(fn (InvoiceLine $line): array => [
                    'line' => $line,
                    'quantity' => $line->quantity,
                ]);
        }

        $lineIds = collect($requestedLines)->pluck('invoice_line_id')->map(fn (mixed $id): string => (string) $id)->all();
        $lines = InvoiceLine::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('id', $lineIds)
            ->get()
            ->keyBy('id');

        return collect($requestedLines)->map(function (array $request) use ($lines): array {
            $line = $lines->get($request['invoice_line_id']);

            if (! $line instanceof InvoiceLine) {
                throw CrossTenantReferenceException::forAttribute('invoice_line_id', $request['invoice_line_id']);
            }

            $quantity = (int) ($request['quantity'] ?? $line->quantity);
            if ($quantity < 1 || $quantity > $line->quantity) {
                throw new InvalidArgumentException('Credit-note line quantity must be between 1 and the original line quantity.');
            }

            return [
                'line' => $line,
                'quantity' => $quantity,
            ];
        })->values();
    }

    private function nextNumber(string $series): int
    {
        $tenantId = $this->tenantContext->id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forQuery(new InvoiceSequence);
        }

        DB::table('invoice_sequences')->insertOrIgnore([
            'tenant_id' => $tenantId,
            'series' => $series,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = InvoiceSequence::query()
            ->where('series', $series)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;
        $sequence->forceFill(['next_number' => $number + 1])->save();

        return $number;
    }

    private function tenantCurrencyFromCharges(Collection $charges): string
    {
        $catalog = $charges->first()?->tariffCatalog;

        return $catalog->currency ?? 'EUR';
    }

    private function date(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface
            ? Carbon::instance($date)->toDateString()
            : Carbon::parse($date)->toDateString();
    }

    private function authorize(User $actor): void
    {
        if (! Gate::forUser($actor)->allows('billing.manage')) {
            throw new AuthorizationException('This user cannot manage billing.');
        }
    }

    private function assertPayerType(string $payerType): void
    {
        if (! in_array($payerType, [Invoice::PAYER_SELF_PAY, Invoice::PAYER_PRIVATE_INSURANCE], true)) {
            throw new InvalidArgumentException('Unsupported invoice payer type.');
        }
    }

    private function assertActorTenant(User $actor): void
    {
        if ($actor->tenant_id !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute('actor_id', (string) $actor->id);
        }
    }

    private function assertSameTenant(object $model, string $attribute): void
    {
        if (($model->tenant_id ?? null) !== $this->tenantContext->id()) {
            throw CrossTenantReferenceException::forAttribute($attribute, (string) ($model->id ?? ''));
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function auditInvoice(string $action, Invoice $invoice, User $actor, array $context = []): void
    {
        $this->audit->record([
            'actor_type' => 'user',
            'actor_id' => (string) $actor->id,
            'action' => $action,
            'patient_id' => $invoice->patient_id,
            'resource_type' => 'invoice',
            'resource_id' => $invoice->id,
            'context' => [
                'series' => $invoice->series,
                'number' => $invoice->number,
                'status' => $invoice->status,
                'subtotal_minor' => $invoice->subtotal_minor,
                'vat_total_minor' => $invoice->vat_total_minor,
                'total_minor' => $invoice->total_minor,
                'credit_note_for_invoice_id' => $invoice->credit_note_for_invoice_id,
                ...$context,
            ],
        ]);
    }
}
