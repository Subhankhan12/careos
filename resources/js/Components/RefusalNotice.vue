<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// A refusal the server made, shown to the person who made the request (QA-FIX.6c, P6-C3).
//
// WHY THIS EXISTS. The Surgery module refuses correctly — sixteen `->withErrors([...])` sites across six
// controllers, plus every `$request->validate()` rule — and NOT ONE Surgery page rendered any of it. A
// refused action and a successful one were byte-identical to the user: the page reloaded, nothing was
// recorded, and no message appeared. Driven in a browser during the Phase 6 audit, a blank implant lot and
// a 99999-unit stock request each returned 302 with no record and no word of explanation.
//
// THE MECHANISM IS NOT NEW. Inertia's own middleware already shares the error bag on every response
// (`Middleware::share()` → `resolveValidationErrors()`), and `Admin/Branches.vue` and the Billing surfaces
// already read `page.props.errors` and render it in exactly these classes. This component is a
// presentation wrapper over that existing path so six pages cannot drift apart in wording or styling —
// it introduces no new error mechanism (D-170).
//
// IT READS THE WHOLE BAG ON PURPOSE. Two different shapes arrive in it: `$request->validate()` keys by
// FIELD (`lot_number`, `quantity`), while the controllers' `withErrors` keys by DOMAIN (`surgical_supplies`,
// `surgical_billing`). Naming keys would silently miss half the refusals — which is how a module can look
// handled while most of its refusals stay invisible. Anything present is shown.
//
// IT ASSERTS NOTHING OF ITS OWN. There is no authored copy here for a refusal that might never happen
// (D-176): the text is the server's own sentence, rendered verbatim, and with an empty bag this component
// renders nothing at all. It cannot manufacture a refusal that did not occur.
//
// `role="alert"` is deliberate and is the one thing here beyond the established pattern: an error a screen
// reader never announces is invisible in exactly the way this finding is about. No other surface in the
// repo does this yet, so it is an addition rather than a divergence.
const page = usePage();

const messages = computed<string[]>(() => {
    const bag = (page.props.errors as Record<string, string> | undefined) ?? {};

    return Object.values(bag).filter((m): m is string => typeof m === 'string' && m.length > 0);
});
</script>

<template>
    <div v-if="messages.length" role="alert" class="rounded-2xl border border-danger/30 bg-danger-soft p-4 text-sm text-danger">
        <p v-for="(message, i) in messages" :key="i" :class="i > 0 ? 'mt-1' : ''">{{ message }}</p>
    </div>
</template>
