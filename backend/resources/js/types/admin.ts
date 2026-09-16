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

export type Computer = {
    id: number;
    school_id: number;
    class_id: number | null;
    name: string;
    role: 'teacher' | 'student';
    tokens_count: number;
    school: SchoolOption | null;
    school_class: ClassOption | null;
};
