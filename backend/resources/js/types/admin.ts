export type SchoolOption = {
    id: number;
    name: string;
};

export type ClassOption = {
    id: number;
    name: string;
    school_id: number;
    grade?: string | null;
    stream?: string | null;
};

export type OrganizationOption = {
    id: number;
    name: string;
};

export type School = {
    id: number;
    name: string;
    classes_count: number;
    students_count: number;
    computers_count: number;
    /** Whether the current viewer may delete this school (organization administrators only). */
    can_delete: boolean;
};

export type SchoolClassRow = {
    id: number;
    name: string;
    grade: string | null;
    stream: string | null;
    school: SchoolOption | null;
    teacher: { id: number; name: string } | null;
    students_count: number;
    computers_count: number;
};

export type Student = {
    id: number;
    school_id: number;
    class_id: number | null;
    admission_number: string;
    full_name: string;
    is_active: boolean;
    school: SchoolOption | null;
    school_class: ClassOption | null;
};

export type ClassWithSchool = ClassOption & {
    school: SchoolOption | null;
};

export type NoUsageStudent = {
    admission_number: string;
    full_name: string;
};

export type GradeOption = {
    school_id: number;
    school_name: string | null;
    grade: string;
    classes_count: number;
};

export type ReportSummary = {
    total_students: number;
    active_students: number;
    average_duration_minutes: number | null;
    total_sessions: number;
};

export type ClassReport = ReportSummary & {
    /** "grade" adds up every class of a grade; `breakdown` then has one row per class. */
    scope: 'class' | 'grade';
    class: { id: number | null; name: string; school_name: string | null };
    breakdown: (ReportSummary & { id: number; name: string; stream: string | null })[];
    no_usage_students: NoUsageStudent[];
};

export type LiveSession = {
    id: number;
    admission_number: string;
    full_name: string | null;
    class_name: string | null;
    school_name: string | null;
    computer_name: string | null;
    login_time: string;
    minutes_logged_in: number;
};

export type Computer = {
    id: number;
    school_id: number;
    class_id: number | null;
    name: string;
    role: 'teacher' | 'student';
    tokens_count: number;
    school: SchoolOption | null;
    school_class: ClassOption | null;
    /** Most recent login_session synced from this computer, if any. */
    last_session_synced_at: string | null;
    /** When this computer's current token last authenticated a request. */
    token_last_used_at: string | null;
};

export type CbcLevel = {
    key: string;
    label: string;
    grades: string[];
    /** Ticked when the seed panel opens. */
    default: boolean;
};
