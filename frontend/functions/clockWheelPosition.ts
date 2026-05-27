/** Short label for slot type/category in UI. */
export function slotValueShortLabel(
    slotValue: string,
    categories: {id: number; name: string}[],
): string {
    if (slotValue.startsWith('cat:')) {
        const id = parseInt(slotValue.slice(4), 10);
        const cat = categories.find((c) => c.id === id);
        return cat?.name ?? slotValue;
    }

    const type = slotValue.replace('type:', '');
    const labels: Record<string, string> = {
        music: 'Music',
        talk: 'Talk',
        id: 'ID',
        promo: 'Promo',
        ad: 'Ad',
    };

    return labels[type] ?? type;
}
