import { createRouter, createWebHistory } from 'vue-router';

export default createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: '/',
            name: 'platform-dashboard',
            component: () => import('@/pages/dashboards/platform/Index.vue'),
        },
        {
            path: '/organizations',
            name: 'organizations',
            component: () => import('@/pages/organizations/Index.vue'),
        },
        {
            path: '/billing/invoices',
            name: 'customer-invoices',
            component: () => import('@/pages/billing/invoices/Index.vue'),
        },
        {
            path: '/profile',
            name: 'account-profile',
            component: () => import('@/pages/profile/Index.vue'),
        },
        {
            path: '/:pathMatch(.*)*',
            component: () => import('@/pages/errors/NotFound.vue'),
        },
    ],
});
