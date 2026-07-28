<?php

namespace Modules\Surgery\Exceptions;

use RuntimeException;

/**
 * Domain errors for the WHO Surgical Safety Checklist (SURGERY.G3). The checklist is a RECORD: the item log is
 * append-only (`appendOnly`); phases are the three canonical WHO phases (`invalidPhase`); a template item
 * needs a label (`labelRequired`); a confirmation must target a known template item (`unknownTemplateItem`).
 * There is NO "checklist failed / not-safe" error — the checklist never gates the surgery.
 */
class SurgicalChecklistException extends RuntimeException
{
    public static function invalidPhase(string $value): self
    {
        return new self("Unknown WHO checklist phase: {$value}. It is one of sign_in / time_out / sign_out.");
    }

    public static function labelRequired(): self
    {
        return new self('A checklist template item must have a label.');
    }

    public static function appendOnly(): self
    {
        return new self('surgical_checklist_items are append-only: a correction is a new confirmation, never an edit.');
    }

    public static function unknownTemplateItem(string $id): self
    {
        return new self("Unknown surgical-checklist template item: {$id}.");
    }
}
