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
            path: '/:pathMatch(.*)*',
            component: () => import('@/pages/errors/NotFound.vue'),
        },
    ],
});
