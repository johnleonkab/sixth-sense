export type User = {
    id: number;
    name: string;
    email: string;
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
