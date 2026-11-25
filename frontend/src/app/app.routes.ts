import { Routes } from '@angular/router';
import { AuthPage, ClassesPage, DashboardPage, LandingPage, NotificationsPage, NotFoundPage } from './pages';

export const routes: Routes = [
  { path: '', component: LandingPage, title: 'InsQuire | Planning, writing, and learning' },
  { path: 'login', component: AuthPage, data: { mode: 'login' }, title: 'Sign in | InsQuire' },
  { path: 'register', component: AuthPage, data: { mode: 'register' }, title: 'Create an account | InsQuire' },
  { path: 'dashboard', component: DashboardPage, title: 'Your workspace | InsQuire' },
  { path: 'classes', component: ClassesPage, title: 'Your classes | InsQuire' },
  { path: 'notifications', component: NotificationsPage, title: 'Notifications | InsQuire' },
  { path: '**', component: NotFoundPage, title: 'Page not found | InsQuire' }
];
