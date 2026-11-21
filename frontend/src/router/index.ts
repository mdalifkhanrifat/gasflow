import { shallowRef } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import ServiceNotice from '@/pages/errors/ServiceNotice.vue';

export const navigationFailure = shallowRef<{
    retryHref: string;
    resumeCurrentPage: boolean;
}>();

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    scrollBehavior(to, _from, savedPosition) {
        return savedPosition ?? (to.hash ? { el: to.hash } : { top: 0 });
    },
    routes: [
        {
            path: '/',
            name: 'platform-dashboard',
            component: () => import('@/pages/dashboards/platform/Index.vue'),
        },
        {
            path: '/welcome',
            name: 'public-landing',
            component: () => import('@/pages/public/Landing.vue'),
        },
        {
            path: '/login',
            name: 'sign-in',
            component: () => import('@/pages/auth/SignIn.vue'),
        },
        {
            path: '/error/403',
            name: 'access-denied',
            component: ServiceNotice,
            props: { mode: 'forbidden' },
            meta: { statusScreen: true },
        },
        {
            path: '/error/500',
            name: 'server-error',
            component: ServiceNotice,
            props: { mode: 'error' },
            meta: { statusScreen: true },
        },
        {
            path: '/maintenance',
            name: 'maintenance',
            component: ServiceNotice,
            props: { mode: 'maintenance' },
            meta: { statusScreen: true },
        },
        {
            path: '/organizations',
            name: 'organizations',
            component: () => import('@/pages/organizations/Index.vue'),
        },
        {
            path: '/billing/cycles',
            name: 'billing-cycles',
            component: () => import('@/pages/billing/cycles/Index.vue'),
        },
        {
            path: '/billing/invoices',
            name: 'customer-invoices',
            component: () => import('@/pages/billing/invoices/Index.vue'),
        },
        {
            path: '/billing/invoices/:number',
            name: 'customer-invoice',
            component: () => import('@/pages/billing/invoices/Show.vue'),
            props: true,
            meta: { navigationRoute: 'customer-invoices' },
        },
        {
            path: '/profile',
            name: 'account-profile',
            component: () => import('@/pages/profile/Index.vue'),
        },
        {
            path: '/:pathMatch(.*)*',
            name: 'not-found',
            component: ServiceNotice,
            props: { mode: 'missing' },
            meta: { statusScreen: true },
        },
    ],
});

router.onError((error, to, from) => {
    console.error(error);
    navigationFailure.value = {
        retryHref: router.resolve(to.fullPath).href,
        resumeCurrentPage: !!from.name && !from.meta.statusScreen,
    };
});

router.afterEach((_to, _from, failure) => {
    if (!failure) {
        navigationFailure.value = undefined;
    }
});

export default router;
