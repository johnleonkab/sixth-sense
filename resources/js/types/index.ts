export type User = {
    id: number;
    name: string;
    email: string;
    /** How much canvases are scaled (Configuración → Sistema). */
    display_scale: number;
};

export type SharedData = {
    auth: {
        user: User | null;
    };
    /** Sound and read-aloud for each aviso source. */
    alertSounds: Record<string, { sound: string; read_aloud: boolean }>;
};

export type Toast = {
    type: 'success' | 'warning' | 'error';
    message: string;
};
