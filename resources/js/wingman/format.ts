const time = new Intl.DateTimeFormat('es-ES', { hour: '2-digit', minute: '2-digit' });
const day = new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long' });

export const timeRange = (from: string, to: string): string => `${time.format(new Date(from))}–${time.format(new Date(to))}`;

export const dayLabel = (iso: string): string => {
    const label = day.format(new Date(iso));

    return label.charAt(0).toUpperCase() + label.slice(1);
};
