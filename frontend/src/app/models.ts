export type AccountScope = 'personal' | 'education';
export type AccountRole = 'student' | 'teacher' | 'admin';

export interface AppUser {
  id: number;
  name: string;
  email: string;
  role: AccountRole;
  account_scope: AccountScope;
}

export interface BootstrapResponse {
  csrf_token: string;
  site_scope: AccountScope;
  google_client_id: string;
  personal_url: string;
  education_url: string;
  show_personal_link: boolean;
  default_education_class: { name: string } | null;
  user: AppUser | null;
}

export interface DashboardResponse {
  user: AppUser;
  summary: {
    classes: number;
    pending_reviews: number;
    upcoming_assignments: number;
  };
  classes: AppClass[];
  tasks: DashboardTask[];
}

export interface AppClass {
  id: number;
  name: string;
  join_code?: string;
  student_count?: number;
  teacher_name?: string;
  link?: string;
}

export interface DashboardTask {
  id: number;
  title: string;
  class_name?: string;
  due_at?: string | null;
  detail?: string;
  link?: string;
}

export interface AppNotification {
  id: number;
  notification_type: 'message' | 'announcement';
  created_at: string;
  read_at: string | null;
  class_id: number;
  class_name: string;
  actor_name: string;
  preview: string;
  sender_id: number;
}

export interface ApiError {
  error: string;
}
