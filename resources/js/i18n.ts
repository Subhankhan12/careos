import { createI18n } from 'vue-i18n';
import en from './lang/en.json';

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en },
});
