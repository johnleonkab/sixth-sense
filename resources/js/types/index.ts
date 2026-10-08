export type User = {
    id: number;
    name: string;
    email: string;
    /** How much canvases are scaled (Configuración → Pantalla). */
    display_scale: number;
};

export type SharedData = {
    auth: {
        user: User | null;
    };
};

export type Toast = {
    type: 'success' | 'warning' | 'error';
    message: string;
};
