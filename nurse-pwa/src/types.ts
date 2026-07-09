export interface DayPack {
    date: string;
    nurse: {
        id: number;
        name: string;
    };
    visits: VisitSummary[];
}

export interface VisitSummary {
    id: string;
    scheduled_date: string;
    window_start_at: string;
    window_end_at: string;
    duration_minutes: number;
    required_qualification: string | null;
    status: string;
    address: AddressSummary;
    patient: PatientSummary;
    tasks: TaskSummary[];
}

export interface AddressSummary {
    line1: string | null;
    line2: string | null;
    city: string | null;
    postal: string | null;
    country: string | null;
}

export interface PatientSummary {
    id: string;
    mrn: string;
    name: string;
    date_of_birth: string;
    sex: string;
    allergies: AllergySummary[];
    medications: MedicationSummary[];
    problems: ProblemSummary[];
    care_plan_goals: CarePlanGoalSummary[];
}

export interface AllergySummary {
    id: string;
    substance: string;
    reaction: string | null;
    severity: string;
}

export interface MedicationSummary {
    id: string;
    name: string;
    dose_text: string | null;
    route: string | null;
    frequency_text: string | null;
}

export interface ProblemSummary {
    id: string;
    description: string;
    code: string | null;
}

export interface CarePlanGoalSummary {
    id: string;
    care_plan_id: string;
    care_plan_title: string;
    description: string;
    target_date: string | null;
}

export interface TaskSummary {
    id: string;
    title: string;
    description: string | null;
    due_at: string;
    priority: string;
    status: string;
}
