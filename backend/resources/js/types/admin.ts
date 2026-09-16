export type SchoolOption = {
    id: number;
    name: string;
};

export type ClassOption = {
    id: number;
    name: string;
    school_id: number;
};

export type School = {
    id: number;
    name: string;
    classes_count: number;
    students_count: number;
    computers_count: number;
};

export type SchoolClassRow = {
    id: number;
    name: string;
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

export type ClassReport = {
    class: { id: number; name: string; school_name: string | null };
    total_students: number;
    active_students: number;
    average_duration_minutes: number | null;
    total_sessions: number;
    no_usage_students: NoUsageStudent[];
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
