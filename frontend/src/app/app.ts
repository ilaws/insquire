import { Component, effect, inject, signal, ViewEncapsulation } from '@angular/core';
import { NavigationEnd, Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';
import { ApiService } from './api.service';
import { EMPTY, catchError, filter, interval, startWith, switchMap } from 'rxjs';

@Component({
  selector: 'app-root',
  imports: [RouterLink, RouterLinkActive, RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.css',
  encapsulation: ViewEncapsulation.None
})
export class App {
  protected readonly api = inject(ApiService);
  private readonly router = inject(Router);
  protected menuOpen = false;
  protected readonly signingOut = signal(false);
  protected readonly notice = signal('');

  constructor() {
    this.router.events.pipe(filter((event) => event instanceof NavigationEnd))
      .subscribe(() => {
        this.menuOpen = false;
        document.body.dataset['appReady'] = 'true';
        window.dispatchEvent(new Event('insquire:navigation'));
      });
    effect((onCleanup) => {
      const user = this.api.user();
      if (user?.account_scope !== 'education' || !['student', 'teacher'].includes(user.role)) {
        this.api.unreadNotificationCount.set(0);
        return;
      }

      const notifications = interval(60_000).pipe(
        startWith(0),
        switchMap(() => this.api.notificationCount().pipe(
          catchError((error: unknown) => {
            console.error('Unable to refresh notification count:', error);
            return EMPTY;
          })
        ))
      ).subscribe();
      onCleanup(() => notifications.unsubscribe());
    });
    this.api.bootstrap().subscribe({
      error: () => {
        this.notice.set('The sign-in service is unavailable. Please try again when the site is connected.');
      }
    });
  }

  protected signOut(): void {
    this.signingOut.set(true);
    this.api.logout().subscribe({
      next: () => this.router.navigateByUrl('/'),
      error: (error: unknown) => {
        this.notice.set(this.api.messageFor(error, 'Unable to sign out. Please try again.'));
        this.signingOut.set(false);
      }
    });
  }
}
