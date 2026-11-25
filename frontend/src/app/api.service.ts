import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, catchError, map, of, tap, throwError } from 'rxjs';
import {
  AccountScope,
  ApiError,
  AppClass,
  AppNotification,
  AppUser,
  BootstrapResponse,
  DashboardResponse
} from './models';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);
  readonly user = signal<AppUser | null>(null);
  readonly unreadNotificationCount = signal(0);
  readonly defaultEducationClass = signal<string | null>(null);
  readonly siteScope = signal<AccountScope>(
    typeof window !== 'undefined' && window.location.hostname === 'ed.insquire.org'
      ? 'education'
      : 'personal'
  );
  readonly googleClientId = signal('');
  readonly personalUrl = signal('https://insquire.org');
  readonly educationUrl = signal('https://ed.insquire.org');
  readonly showPersonalLink = signal(false);
  readonly ready = signal(false);
  private csrfToken = '';

  bootstrap(): Observable<BootstrapResponse> {
    return this.http.get<BootstrapResponse>('/api/v1/bootstrap.php', {
      withCredentials: true
    }).pipe(
      tap((response) => {
        this.csrfToken = response.csrf_token;
        this.siteScope.set(response.site_scope);
        this.googleClientId.set(response.google_client_id);
        if (response.personal_url) this.personalUrl.set(response.personal_url);
        if (response.education_url) this.educationUrl.set(response.education_url);
        this.showPersonalLink.set(response.show_personal_link === true);
        this.defaultEducationClass.set(response.default_education_class?.name ?? null);
        this.user.set(response.user);
        this.ready.set(true);
      })
    );
  }

  login(email: string, password: string): Observable<AppUser> {
    return this.post<{ email: string; password: string }, { user: AppUser; csrf_token: string }>(
      '/api/v1/auth/login.php',
      { email, password }
    ).pipe(
      tap((response) => { this.csrfToken = response.csrf_token; }),
      map((response) => response.user),
      tap((user) => this.user.set(user))
    );
  }

  loginWithGoogle(credential: string): Observable<AppUser> {
    return this.post<{ credential: string }, { user: AppUser; csrf_token: string }>(
      '/api/v1/auth/login.php',
      { credential }
    ).pipe(
      tap((response) => { this.csrfToken = response.csrf_token; }),
      map((response) => response.user),
      tap((user) => this.user.set(user))
    );
  }

  register(input: {
    name: string;
    email: string;
    password: string;
    school_code?: string;
    invite_token?: string;
    credential?: string;
  }): Observable<{ message: string }> {
    return this.post<typeof input, { message: string }>('/api/v1/auth/register.php', input);
  }

  registerWithGoogle(input: {
    credential: string;
    school_code?: string;
    invite_token?: string;
  }): Observable<{ message: string }> {
    return this.post<typeof input, { message: string }>('/api/v1/auth/register.php', input);
  }

  acceptInvitation(inviteToken: string): Observable<{ success: boolean }> {
    return this.post<{ invite_token: string }, { success: boolean }>(
      '/api/v1/auth/invitation.php',
      { invite_token: inviteToken }
    );
  }

  logout(): Observable<void> {
    return this.post<Record<string, never>, { success: boolean }>(
      '/api/v1/auth/logout.php',
      {}
    ).pipe(
      tap(() => this.user.set(null)),
      map(() => undefined)
    );
  }

  dashboard(): Observable<DashboardResponse> {
    return this.http.get<DashboardResponse>('/api/v1/dashboard.php', {
      withCredentials: true
    });
  }

  classes(): Observable<{ classes: AppClass[] }> {
    return this.http.get<{ classes: AppClass[] }>('/api/v1/classes.php', {
      withCredentials: true
    });
  }

  notificationCount(): Observable<{ unread: number }> {
    return this.http.get<{ unread: number }>('/notification-count.php', {
      withCredentials: true,
      headers: new HttpHeaders({ Accept: 'application/json' })
    }).pipe(tap(({ unread }) => this.unreadNotificationCount.set(unread)));
  }

  notifications(): Observable<{ notifications: AppNotification[] }> {
    return this.http.get<{ notifications: AppNotification[] }>('/api/v1/notifications.php', {
      withCredentials: true
    });
  }

  markNotificationRead(notificationId: number): Observable<{ success: boolean }> {
    return this.post<{ notification_id: number }, { success: boolean }>(
      '/api/v1/notifications.php',
      { notification_id: notificationId }
    );
  }

  joinClass(joinCode: string): Observable<{ success: boolean }> {
    return this.post<{ join_code: string }, { success: boolean }>(
      '/api/v1/classes/join.php',
      { join_code: joinCode }
    );
  }

  messageFor(error: unknown, fallback: string): string {
    if (error instanceof HttpErrorResponse) {
      if (error.status === 0) {
        return 'Cannot reach the sign-in service. The site API may be unavailable.';
      }
      const payload = error.error as ApiError | null;
      if (payload && typeof payload.error === 'string') {
        return payload.error;
      }
      if (error.status === 401) {
        return 'Email or password is incorrect.';
      }
      if (error.status === 403) {
        return 'You do not have permission to complete this request.';
      }
      if (error.status === 404) {
        return 'The requested page or service could not be found.';
      }
      if (error.status === 405) {
        return 'This action is not available for this request.';
      }
      if (error.status === 419) {
        return 'Your security token has expired. Refresh the page and try again.';
      }
      if (error.status === 429) {
        return 'Too many requests. Please wait before trying again.';
      }
      if (error.status >= 500) {
        return 'The service is temporarily unavailable. Please try again shortly.';
      }
    }
    if (error instanceof Error && error.message === 'The security token is not ready. Refresh and try again.') {
      return 'The sign-in service did not finish loading. Refresh the page and try again.';
    }
    return fallback;
  }

  private post<TInput, TOutput>(url: string, input: TInput): Observable<TOutput> {
    if (!this.csrfToken) {
      return throwError(() => new Error('The security token is not ready. Refresh and try again.'));
    }

    return this.http.post<TOutput>(url, input, {
      withCredentials: true,
      headers: new HttpHeaders({ 'X-CSRF-Token': this.csrfToken })
    }).pipe(
      catchError((error: unknown) => {
        if (error instanceof HttpErrorResponse && error.status === 419) {
          this.ready.set(false);
          return this.bootstrap().pipe(
            catchError(() => throwError(() => error)),
            map(() => {
              throw error;
            })
          );
        }
        return throwError(() => error);
      })
    );
  }
}
