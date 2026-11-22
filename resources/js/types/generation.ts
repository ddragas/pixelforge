export type GenerationStatus = 'pending' | 'queued' | 'processing' | 'succeeded' | 'failed';

export interface Estimate {
    lowest: string;
    expected: string;
    highest: string;
}

export interface Generation {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: GenerationStatus;
    error_message: string | null;
    estimate: Estimate;
    charged: string | null;
    image_url: string | null;
    image_expired: boolean;
    created_at: string;
    completed_at: string | null;
}

export interface Credit {
    balance: string;
    available: string;
}
