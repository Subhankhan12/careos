import { createI18n } from 'vue-i18n';

export const messages = {
    en: {
        app: {
            title: 'CareOS Nurse',
            sync: 'Sync',
            logout: 'Log out',
        },
        login: {
            title: 'Nurse login',
            email: 'Email',
            password: 'Password',
            submit: 'Sign in',
        },
        visits: {
            today: 'Today',
            empty: 'No synced visits.',
            detail: 'Visit detail',
            back: 'Back',
            tasks: 'Tasks',
            medications: 'Medications',
            problems: 'Problems',
            goals: 'Care goals',
            allergies: 'Allergies',
            noAllergies: 'No active allergies in this day-pack.',
            offline: 'Offline day-pack ready',
        },
        sync: {
            pending: 'Pending offline actions: {count}',
            lastSynced: 'Last synced: {time}',
            error: 'Sync needs retry.',
        },
    },
};

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages,
});
