import type { SharedData, Toast } from '@/types';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: SharedData;
        flashDataType: {
            toast?: Toast;
            /** The Wingman extension's new token: shown once, right after making it. */
            wingman_token?: string;
        };
    }
}

declare global {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_REVERB_APP_KEY: string;
        readonly VITE_REVERB_HOST: string;
        readonly VITE_REVERB_PORT?: string;
        readonly VITE_REVERB_SCHEME?: string;
    }
}
