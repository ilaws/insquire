import { AfterViewInit, Component, effect, ElementRef, inject, NgZone, signal, ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { DOCUMENT } from '@angular/common';
import { ApiService } from './api.service';
import { AppClass, AppNotification, DashboardResponse } from './models';

interface GoogleCredentialResponse {
  credential: string;
}

interface GoogleIdentityApi {
  accounts: {
    id: {
      initialize(options: {
        client_id: string;
        callback: (response: GoogleCredentialResponse) => void;
      }): void;
      renderButton(element: HTMLElement, options: {
        theme: 'outline';
        size: 'large';
        shape: 'rectangular';
        text: 'signin_with' | 'signup_with';
        width: number;
      }): void;
    };
  };
}

declare global {
  interface Window {
    google?: GoogleIdentityApi;
  }
}

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <section class="hero-wrap" [class.education-landing]="api.siteScope() === 'education'">
      <div class="hero-copy">
        @if (api.siteScope() === 'education') {
          <h1>Build your writing skills,<br>one draft at a time.</h1>
          <p class="hero-lede">Write, review your draft, and decide what to revise. InsQuire’s AI gives quick feedback on strengths and possible next steps. The ideas and final choices stay yours.</p>
          <div class="hero-actions">
            <a class="button button-primary" routerLink="/register">Sign up</a>
            <a class="button button-quiet" routerLink="/login">Sign in</a>
          </div>
        } @else {
          <h1>Assignments and<br>writing projects.</h1>
          <p class="hero-lede">Track due dates and plan writing projects with InsQuire Personal.</p>
          <div class="hero-actions">
            <a class="button button-primary" routerLink="/register">Create a personal account</a>
            <a class="button button-quiet" routerLink="/login">Sign in</a>
          </div>
        }
      </div>
      <div class="hero-art">
        @if (api.siteScope() === 'education') {
          <section class="product-preview writing-feedback-preview" aria-label="How InsQuire AI writing feedback helps">
            <h2>Learn from each draft</h2>
            <p class="writing-feedback-intro">AI feedback gives you another perspective. You choose your next step.</p>
            <article class="writing-feedback-item">
              <span class="writing-feedback-mark">+</span>
              <div><h3>Notice what’s working</h3><p>Recognize strengths you can carry into your next draft.</p></div>
            </article>
            <article class="writing-feedback-item">
              <span class="writing-feedback-mark">↗</span>
              <div><h3>Find a place to grow</h3><p>Consider where a clearer point or stronger detail could help.</p></div>
            </article>
            <article class="writing-feedback-item">
              <span class="writing-feedback-mark">?</span>
              <div><h3>Choose what to revise</h3><p>Use questions to guide your thinking. The writing stays yours.</p></div>
            </article>
          </section>
        } @else {
          <section class="product-preview" aria-label="Example personal planner">
            <div class="preview-heading"><span>INSQUIRE PERSONAL</span><span>PLANNER</span></div>
            <h2>Keep your projects moving.</h2>
            <div class="preview-row"><span>Projects</span><strong>Plan your next steps</strong><small>Writing workspace</small></div>
            <div class="preview-row"><span>Assignments</span><strong>Keep track of due dates</strong><small>Personal planner</small></div>
            <div class="preview-row"><span>Progress</span><strong>Return to saved work</strong><small>Pick up where you left off</small></div>
          </section>
        }
      </div>
    </section>
    <section class="value-strip" aria-label="What you can do with InsQuire">
      @if (api.siteScope() === 'education') {
        <article><div><h2>Build writing skills</h2><p>Practice with feedback that helps you notice strengths and areas to work on.</p></div></article>
        <article><div><h2>Learn through revision</h2><p>Think through suggestions and choose the changes that make sense to you.</p></div></article>
        <article><div><h2>Keep your voice</h2><p>AI offers another perspective. Your ideas and revisions remain your own.</p></div></article>
      } @else {
        <article><div><h2>Plan</h2><p>Break writing projects into steps you can work through.</p></div></article>
        <article><div><h2>Keep track</h2><p>See personal assignments and the dates you set for them.</p></div></article>
        <article><div><h2>Pick up where you left off</h2><p>Keep your planning and writing projects together.</p></div></article>
      }
    </section>
    @if (api.siteScope() === 'education') {
      <section class="education-details">
        <div class="education-details-heading">
          <h2>Writing improves with practice.</h2>
          <p>InsQuire brings assignments, teacher guidance, and AI feedback into the writing process. Students get suggestions to consider, then make their own decisions and revisions.</p>
        </div>
        <div class="education-audience-grid">
          <article>
            <h3>For students</h3>
            <p>Work on a class assignment and get AI feedback while you write. Notice what’s working, consider a question or suggestion, and decide how to revise in your own words. Your teacher’s comments and grade stay with the assignment.</p>
          </article>
          <article>
            <h3>For teachers</h3>
            <p>Set the assignment and expectations, then review student submissions and the AI feedback they received. Add your own comments and grade, and use class rosters and gradebooks to follow student work.</p>
          </article>
        </div>
        <section class="education-ai-approach" aria-labelledby="education-ai-approach-title">
          <h2 id="education-ai-approach-title">AI can give feedback. The student does the writing.</h2>
          <p>Some AI writing tools can give students a finished answer or rewrite their work. That skips the planning and revision where learning happens. InsQuire responds to a student’s draft with strengths, areas to consider, and questions. AI can miss context or make suggestions that do not fit, so students use their judgment and ask their teacher for guidance. They make the final decisions and revisions.</p>
        </section>
        <section class="teacher-testimonial" aria-labelledby="teacher-testimonial-title">
          <h2 id="teacher-testimonial-title">A teacher’s perspective</h2>
          <blockquote>“When AI came along, [cheating] was so predominant during hard writing tasks, that I have had to go back to making [students] produce essays in class periods in front of me, and I am also making them produce their pre-planning ahead of time, by hand, so that I can verify these were [the students’] thoughts . . . There’s so much that AI could hopefully help with giving feedback, but currently, where we are, truly evaluating writing for organization of ideas and connection, I just haven’t seen a good AI tool that does that yet.”</blockquote>
          <p class="teacher-testimonial-attribution">Teacher, Statesville, NC</p>
        </section>
        <div class="education-signup">
          <div>
            <h2>Getting started</h2>
            <p>Students can create an account without a class code and start in the InsQuire Test class. Teacher accounts are created through a school invitation.</p>
          </div>
          <a class="button button-primary" routerLink="/register">Sign up</a>
        </div>
      </section>
    } @else {
      <section class="school-callout">
        <div><h2>For teachers and schools.</h2><p>Manage classes, assignments, student submissions, and feedback in a school account.</p></div>
        <a [href]="api.educationUrl() + '/'" class="text-link">Visit InsQuire Education</a>
      </section>
    }
  `
})
export class LandingPage {
  protected readonly api = inject(ApiService);
}

@Component({
  standalone: true,
  imports: [FormsModule, RouterLink],
  template: `
    <section class="auth-layout">
      <div class="auth-card">
        <div class="auth-card-heading">
          <span class="form-kicker">{{ api.siteScope() === 'education' ? 'INSQUIRE FOR EDUCATION' : 'INSQUIRE PERSONAL' }}</span>
          <h2>{{ isRegister ? 'Create your account' : 'Sign in' }}</h2>
          <p>{{ isRegister ? (api.siteScope() === 'education' ? (inviteToken ? 'Complete this invitation to create your teacher account.' : api.defaultEducationClass() ? 'Create a student account. It will join ' + api.defaultEducationClass() + ' automatically.' : 'Use the class code provided by your teacher.') : 'Create an account for your personal workspace.') : (api.siteScope() === 'education' ? 'Use the account provided by your school.' : 'Enter your email address and password.') }}</p>
        </div>
        @if (accountCreated) { <div class="success-note" role="status">Your account is ready. Sign in to continue.</div> }
        @if (error()) { <div class="form-alert" role="alert">{{ error() }}</div> }
        @if (isRegister && api.siteScope() === 'education' && inviteToken) {
          <div class="invite-note">This school invitation is for a <strong>teacher account</strong>.</div>
        }
        <form (ngSubmit)="submit()" #authForm="ngForm">
          @if (isRegister && api.siteScope() === 'education' && !inviteToken) {
            @if (api.defaultEducationClass(); as defaultClass) {
              <p class="form-hint">Your student account will join {{ defaultClass }} automatically. Enter a different class code only if your school gave you one.</p>
              <label for="schoolCode">Another class code (optional)</label>
              <input id="schoolCode" name="schoolCode" [(ngModel)]="schoolCode" autocomplete="off" placeholder="Enter a different class code">
            } @else {
              <label for="schoolCode">Class join code</label>
              <input id="schoolCode" name="schoolCode" [(ngModel)]="schoolCode" required autocomplete="off" placeholder="Enter the code from your teacher">
            }
          }
          @if (isRegister) {
            <label for="name">Your name</label>
            <input id="name" name="name" [(ngModel)]="name" required maxlength="120" autocomplete="name" placeholder="e.g. Jordan Lee">
          }
          <label for="email">Email address</label>
          <input id="email" name="email" [(ngModel)]="email" type="email" required maxlength="190" autocomplete="email" placeholder="you@example.com">
          <label for="password">Password</label>
          <input id="password" name="password" [(ngModel)]="password" type="password" required [minlength]="isRegister ? 8 : 1" [autocomplete]="isRegister ? 'new-password' : 'current-password'" [placeholder]="isRegister ? 'At least 8 characters' : 'Enter your password'">
          @if (isRegister) {
            <p class="form-hint">Password must be at least 8 characters.</p>
          }
          <button class="button button-primary auth-submit" type="submit" [disabled]="busy() || authForm.invalid || !api.ready()">
            {{ busy() ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in' }}
          </button>
        </form>
        @if (api.googleClientId()) {
          <div class="auth-divider"><span></span>OR<span></span></div>
          <div #googleButton class="google-button-wrap" aria-label="Continue with Google"></div>
        }
        <p class="auth-switch">
          {{ isRegister ? 'Already have an account?' : 'Need an account?' }}
          <a [routerLink]="isRegister ? '/login' : '/register'" [queryParams]="inviteToken ? { invite: inviteToken } : {}">
            {{ isRegister ? 'Sign in' : api.siteScope() === 'education' ? 'Sign up' : 'Create an account' }}
          </a>
        </p>
        @if (api.siteScope() === 'education') {
        }
      </div>
      <aside class="auth-story">
        @if (api.siteScope() === 'education') {
          <span class="eyebrow">SCHOOL ACCOUNTS</span>
          <h1>School-managed access</h1>
        } @else {
          <h1>Personal account</h1>
          <p>This sign-in is for InsQuire Personal. For school access, visit <a [href]="api.educationUrl() + '/'">InsQuire Education</a>.</p>
        }
      </aside>
    </section>
  `
})
export class AuthPage implements AfterViewInit {
  protected readonly api = inject(ApiService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly document = inject(DOCUMENT);
  private readonly zone = inject(NgZone);
  private googleButton?: ElementRef<HTMLDivElement>;
  @ViewChild('googleButton')
  private set googleButtonRef(element: ElementRef<HTMLDivElement> | undefined) {
    this.googleButton = element;
    if (element) this.renderGoogleButton();
  }
  protected readonly isRegister: boolean;
  protected readonly inviteToken: string;
  protected readonly accountCreated: boolean;
  protected name = '';
  protected email = '';
  protected password = '';
  protected schoolCode = '';
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  constructor() {
    this.isRegister = this.route.snapshot.data['mode'] === 'register';
    this.inviteToken = this.route.snapshot.queryParamMap.get('invite') ?? '';
    this.accountCreated = this.route.snapshot.queryParamMap.has('created');
    effect(() => {
      if (this.api.googleClientId()) this.renderGoogleButton();
    });
  }

  ngAfterViewInit(): void {
    const browser = this.document.defaultView;
    if (!browser) return;
    if (browser.google) {
      this.renderGoogleButton();
      return;
    }

    const existingScript = this.document.querySelector<HTMLScriptElement>('script[data-google-identity]');
    if (existingScript) {
      existingScript.addEventListener('load', () => this.renderGoogleButton(), { once: true });
      return;
    }

    const script = this.document.createElement('script');
    script.src = 'https://accounts.google.com/gsi/client';
    script.async = true;
    script.defer = true;
    script.dataset['googleIdentity'] = 'true';
    script.addEventListener('load', () => this.renderGoogleButton(), { once: true });
    this.document.head.appendChild(script);
  }

  protected submit(): void {
    this.error.set('');
    this.busy.set(true);
    const onSuccess = (): void => {
      if (this.isRegister) {
        this.busy.set(false);
        this.router.navigateByUrl('/login?created=1');
      } else {
        this.finishLogin();
      }
    };
    const onError = (error: unknown): void => {
      this.error.set(this.api.messageFor(error, 'We could not complete that request. Please try again.'));
      this.busy.set(false);
    };

    if (this.isRegister) {
      this.api.register({
        name: this.name.trim(),
        email: this.email.trim(),
        password: this.password,
        ...(this.schoolCode ? { school_code: this.schoolCode.trim().toUpperCase() } : {}),
        ...(this.inviteToken ? { invite_token: this.inviteToken } : {})
      }).subscribe({ next: onSuccess, error: onError });
    } else {
      this.api.login(this.email.trim(), this.password)
        .subscribe({ next: onSuccess, error: onError });
    }
  }

  private renderGoogleButton(): void {
    const clientId = this.api.googleClientId();
    const google = this.document.defaultView?.google;
    const container = this.googleButton?.nativeElement;
    if (!clientId || !google || !container) return;

    container.replaceChildren();
    google.accounts.id.initialize({
      client_id: clientId,
      callback: (response) => this.zone.run(() => this.submitGoogle(response.credential))
    });
    google.accounts.id.renderButton(container, {
      theme: 'outline',
      size: 'large',
      shape: 'rectangular',
      text: this.isRegister ? 'signup_with' : 'signin_with',
      width: Math.min(380, Math.max(260, container.clientWidth))
    });
  }

  private submitGoogle(credential: string): void {
    this.error.set('');
    this.busy.set(true);
    const onError = (error: unknown): void => {
      this.error.set(this.api.messageFor(error, 'Google sign-in could not be completed. Please try again.'));
      this.busy.set(false);
    };

    if (this.isRegister) {
      this.api.registerWithGoogle({
        credential,
        ...(this.schoolCode ? { school_code: this.schoolCode.trim().toUpperCase() } : {}),
        ...(this.inviteToken ? { invite_token: this.inviteToken } : {})
      }).subscribe({
        next: () => {
          this.busy.set(false);
          this.router.navigateByUrl('/login?created=1');
        },
        error: onError
      });
      return;
    }

    this.api.loginWithGoogle(credential).subscribe({
      next: () => this.finishLogin(),
      error: onError
    });
  }

  private finishLogin(): void {
    if (this.inviteToken) {
      if (this.api.user()?.role !== 'teacher') {
        this.error.set('Sign in with a teacher account to accept this school invitation.');
        this.busy.set(false);
        return;
      }
      this.api.acceptInvitation(this.inviteToken).subscribe({
        next: () => { this.busy.set(false); this.router.navigateByUrl('/dashboard'); },
        error: (error: unknown) => {
          this.error.set(this.api.messageFor(error, 'Unable to accept this invitation.'));
          this.busy.set(false);
        }
      });
      return;
    }
    this.busy.set(false);
    this.router.navigateByUrl('/dashboard');
  }
}

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <section class="app-page">
      @if (!api.ready()) {
        <div class="loading-panel"><span class="loading-orb"></span><p>Loading account…</p></div>
      } @else if (!api.user()) {
        <div class="empty-state">
          <h1>Sign in to continue</h1>
          <p>Your classes and assignments are available after you sign in.</p>
          <a class="button button-primary" routerLink="/login">Sign in</a>
        </div>
      } @else if (loading()) {
        <div class="loading-panel"><span class="loading-orb"></span><p>Loading your workspace…</p></div>
      } @else if (error()) {
        <div class="empty-state"><span class="empty-icon">!</span><h1>We couldn't load your workspace</h1><p>{{ error() }}</p><button class="button button-primary" type="button" (click)="load()">Try again</button></div>
      } @else if (data(); as dashboard) {
        <div class="dashboard-heading">
          <div><span class="eyebrow">{{ roleLabel }}</span><h1>Welcome, {{ dashboard.user.name.split(' ')[0] }}.</h1><p>Assignments and classes associated with this account.</p></div>
          <div class="today-pill"><span class="today-dot"></span>{{ today }}</div>
        </div>
        <section class="stats-grid" [class.stats-grid-personal]="dashboard.user.account_scope === 'personal'" aria-label="Account summary">
          @if (dashboard.user.account_scope === 'education') {
            <article class="stat-card"><span class="stat-label">CLASSES</span><strong>{{ dashboard.summary.classes }}</strong><span class="stat-caption">associated with your account</span></article>
          }
          <article class="stat-card"><span class="stat-label">{{ dashboard.user.role === 'teacher' ? 'SUBMISSIONS TO REVIEW' : 'UPCOMING ASSIGNMENTS' }}</span><strong>{{ dashboard.user.role === 'teacher' ? dashboard.summary.pending_reviews : dashboard.summary.upcoming_assignments }}</strong><span class="stat-caption">{{ dashboard.user.role === 'teacher' ? 'awaiting feedback' : 'in your account' }}</span></article>
        </section>
        <div class="dashboard-columns">
          <section class="surface-card">
            <div class="section-heading"><div><span class="section-kicker">ASSIGNMENTS</span><h2>{{ dashboard.user.role === 'teacher' ? 'Submissions to review' : 'Upcoming' }}</h2></div><span class="section-count">{{ dashboard.tasks.length }}</span></div>
            @if (dashboard.tasks.length) {
              <div class="task-list">
                @for (task of dashboard.tasks; track task.id) {
                  <article class="task-row">
                    <span class="task-marker">{{ task.class_name ? 'C' : '•' }}</span>
                    <div class="task-main">
                      @if (task.link) { <a class="task-title-link" [href]="task.link">{{ task.title }}</a> }
                      @else { <strong>{{ task.title }}</strong> }
                      <span>{{ task.class_name || task.detail || 'Personal workspace' }}</span>
                    </div>
                    <span class="task-date">{{ formatDate(task.due_at) }}</span>
                  </article>
                }
              </div>
            } @else {
              <div class="inline-empty"><div><strong>No assignments to show.</strong><p>New assignments will appear here when they are available.</p></div></div>
            }
          </section>
          <section class="surface-card">
            <div class="section-heading"><div><span class="section-kicker">{{ dashboard.user.account_scope === 'education' ? 'CLASSES' : 'PERSONAL' }}</span><h2>{{ dashboard.user.account_scope === 'education' ? 'Your classes' : 'Writing and planning' }}</h2></div>
              @if (dashboard.user.account_scope === 'education') { <a class="small-text-link" routerLink="/classes">View all</a> }
            </div>
            @if (dashboard.classes.length) {
              <div class="class-list">
                @for (item of dashboard.classes; track item.id) {
                  <a class="class-row" [href]="item.link"><span class="class-avatar">{{ item.name.charAt(0).toUpperCase() }}</span><div><strong>{{ item.name }}</strong><span>{{ item.student_count ?? 0 }} learners</span></div><span class="row-arrow">↗</span></a>
                }
              </div>
            } @else if (dashboard.user.account_scope === 'education') {
              <div class="inline-empty"><div><strong>No classes found.</strong><p>Join with a class code or ask your school administrator for access.</p></div></div>
              @if (dashboard.user.role === 'student') { <a class="small-text-link" routerLink="/classes">Enter a class code</a> }
            } @else {
              <div class="personal-card"><strong>Organize personal work</strong><p>Use your planner to track deadlines and writing projects.</p><a href="/personal-planner.php" class="small-text-link">Open planner</a></div>
            }
          </section>
        </div>
      }
    </section>
  `
})
export class DashboardPage {
  protected readonly api = inject(ApiService);
  protected readonly data = signal<DashboardResponse | null>(null);
  protected readonly loading = signal(true);
  protected readonly error = signal('');
  protected readonly today = new Intl.DateTimeFormat(undefined, { weekday: 'long', month: 'long', day: 'numeric' }).format(new Date());

  constructor() {
    this.load();
  }

  protected get roleLabel(): string {
    if (this.api.user()?.account_scope === 'personal') return 'PERSONAL ACCOUNT';
    const role = this.api.user()?.role;
    return role === 'teacher' ? 'TEACHER WORKSPACE' : role === 'student' ? 'STUDENT WORKSPACE' : 'PLATFORM ADMINISTRATION';
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.dashboard().subscribe({
      next: (response) => { this.data.set(response); this.loading.set(false); },
      error: (error: unknown) => {
        this.error.set(this.api.messageFor(error, 'Please try again in a moment.'));
        this.loading.set(false);
      }
    });
  }

  protected formatDate(value?: string | null): string {
    if (!value) return 'No due date';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? 'Upcoming'
      : new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric' }).format(date);
  }
}

@Component({
  standalone: true,
  imports: [FormsModule],
  template: `
    <section class="app-page">
      <div class="dashboard-heading">
        <div><span class="eyebrow">EDUCATION</span><h1>Your classes</h1><p>View the classes linked to your school account.</p></div>
        @if (api.user()?.role === 'teacher') { <span class="role-pill">TEACHER</span> }
      </div>
      @if (error()) { <div class="form-alert" role="alert">{{ error() }}</div> }
      @if (api.user()?.role === 'student') {
        <section class="join-panel">
          <div class="join-panel-copy"><span class="section-kicker">STUDENT ACCESS</span><h2>Join a class</h2><p>Enter the class code provided by your teacher.</p></div>
          <form class="join-form" (ngSubmit)="joinClass()"><label class="sr-only" for="joinCode">Class join code</label><input id="joinCode" name="joinCode" [(ngModel)]="joinCode" required placeholder="Enter class code"><button class="button button-primary" type="submit" [disabled]="busy() || !joinCode.trim()">Join class</button></form>
        </section>
      } @else if (api.user()?.role === 'teacher') {
        <div class="join-panel teacher-note"><div class="join-panel-copy"><span class="section-kicker">TEACHER TOOLS</span><h2>Create a class</h2><p>Create a class and share its join code with students.</p></div><a class="button button-primary" href="/create-class.php">Create a class</a></div>
      }
      @if (busy()) { <div class="loading-panel"><span class="loading-orb"></span><p>Updating your classes…</p></div> }
      @else if (classes().length) {
        <div class="class-grid">
          @for (item of classes(); track item.id) {
            <a class="class-card" [href]="item.link">
              <div class="class-card-top"><span class="class-avatar">{{ item.name.charAt(0).toUpperCase() }}</span><span class="class-open">ACTIVE</span></div>
              <h2>{{ item.name }}</h2><p>{{ item.teacher_name || (api.user()?.role === 'teacher' ? 'Your class' : 'Classroom') }}</p>
              <div class="class-card-bottom"><span>{{ item.student_count ?? 0 }} {{ (item.student_count ?? 0) === 1 ? 'learner' : 'learners' }}</span>
                @if (item.join_code) { <span class="code-pill">CODE {{ item.join_code }}</span> }
              </div>
            </a>
          }
        </div>
      } @else {
        <div class="empty-state"><h2>No classes found.</h2><p>{{ api.user()?.role === 'student' ? 'Enter a class code from your teacher to join a class.' : 'Create a class to begin adding students.' }}</p></div>
      }
    </section>
  `
})
export class ClassesPage {
  protected readonly api = inject(ApiService);
  protected readonly classes = signal<AppClass[]>([]);
  protected joinCode = '';
  protected readonly busy = signal(false);
  protected readonly error = signal('');

  constructor() {
    this.load();
  }

  private load(): void {
    this.api.classes().subscribe({
      next: (response) => { this.classes.set(response.classes); },
      error: (error: unknown) => { this.error.set(this.api.messageFor(error, 'Unable to load classes.')); }
    });
  }

  protected joinClass(): void {
    this.error.set('');
    this.busy.set(true);
    this.api.joinClass(this.joinCode.trim().toUpperCase()).subscribe({
      next: () => { this.joinCode = ''; this.busy.set(false); this.load(); },
      error: (error: unknown) => {
        this.error.set(this.api.messageFor(error, 'Unable to join that class.'));
        this.busy.set(false);
      }
    });
  }
}

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <section class="app-page">
      <div class="dashboard-heading">
        <div><span class="eyebrow">CLASS UPDATES</span><h1>Notifications</h1><p>New private messages and class announcements appear here.</p></div>
        <a class="small-text-link" routerLink="/dashboard">Back to overview</a>
      </div>
      @if (error()) { <div class="form-alert" role="alert">{{ error() }}</div> }
      @if (notice()) { <div class="success-note" role="status">{{ notice() }}</div> }
      @if (loading()) {
        <div class="loading-panel"><span class="loading-orb"></span><p>Loading notifications…</p></div>
      } @else if (!notifications().length) {
        <div class="empty-state"><h2>You're all caught up.</h2><p>New private messages and class announcements will appear here.</p></div>
      } @else {
        <div class="notification-list">
          @for (item of notifications(); track item.id) {
            <article class="notification-card" [class.notification-unread]="!item.read_at">
              <div class="notification-card-content">
                <strong>{{ item.notification_type === 'message' ? 'New message' : 'Class announcement' }}</strong>
                <p class="notification-meta">{{ item.class_name }} · {{ item.actor_name }}</p>
                <p class="notification-preview">{{ item.preview }}</p>
                <small>{{ formatDate(item.created_at) }}</small>
                <p>
                  @if (item.notification_type === 'message') {
                    <a class="text-link" [href]="'/messages.php?class_id=' + item.class_id + '&amp;with=' + item.sender_id + '#private-messages'">Open conversation</a>
                  } @else {
                    <a class="text-link" [href]="'/messages.php?class_id=' + item.class_id + '#announcements'">View announcement</a>
                  }
                </p>
              </div>
              @if (!item.read_at) {
                <button class="button button-quiet" type="button" [disabled]="markingId() === item.id" (click)="markRead(item)">
                  {{ markingId() === item.id ? 'Saving…' : 'Mark read' }}
                </button>
              }
            </article>
          }
        </div>
      }
    </section>
  `
})
export class NotificationsPage {
  private readonly api = inject(ApiService);
  protected readonly notifications = signal<AppNotification[]>([]);
  protected readonly loading = signal(true);
  protected readonly error = signal('');
  protected readonly notice = signal('');
  protected readonly markingId = signal<number | null>(null);

  constructor() {
    this.load();
  }

  protected load(): void {
    this.loading.set(true);
    this.error.set('');
    this.api.notifications().subscribe({
      next: (response) => {
        this.notifications.set(response.notifications);
        this.loading.set(false);
      },
      error: (error: unknown) => {
        this.error.set(this.api.messageFor(error, 'Unable to load notifications.'));
        this.loading.set(false);
      }
    });
  }

  protected markRead(item: AppNotification): void {
    this.error.set('');
    this.notice.set('');
    this.markingId.set(item.id);
    this.api.markNotificationRead(item.id).subscribe({
      next: () => {
        this.notifications.update((items) => items.map((notification) =>
          notification.id === item.id
            ? { ...notification, read_at: new Date().toISOString() }
            : notification
        ));
        this.markingId.set(null);
        this.notice.set('Notification marked as read.');
        this.api.notificationCount().subscribe({
          error: (error: unknown) => console.error('Unable to refresh notification count:', error)
        });
      },
      error: (error: unknown) => {
        this.error.set(this.api.messageFor(error, 'Unable to update this notification.'));
        this.markingId.set(null);
      }
    });
  }

  protected formatDate(value: string): string {
    const date = new Date(value);
    return Number.isNaN(date.getTime())
      ? 'Date unavailable'
      : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
  }
}

@Component({
  standalone: true,
  imports: [RouterLink],
  template: `
    <section class="empty-state not-found">
      <span class="empty-icon">404</span><h1>That page isn't here.</h1>
      <p>Let's get you somewhere useful instead.</p>
      <a class="button button-primary" routerLink="/">Back to InsQuire <span aria-hidden="true">→</span></a>
    </section>
  `
})
export class NotFoundPage {}
