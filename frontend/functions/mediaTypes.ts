export type MediaTypeValue = 'music' | 'talk' | 'id' | 'promo' | 'ad';

export type MediaTypeOption = {
    value: MediaTypeValue;
    label: string;
};

export const getMediaTypeOptions = ($gettext: (msg: string) => string): MediaTypeOption[] => [
    {
        value: 'music',
        label: $gettext('Music (music and copyrighted material)'),
    },
    {
        value: 'talk',
        label: $gettext('Talk (sermons, speeches, and live recordings)'),
    },
    {
        value: 'id',
        label: $gettext('ID (station identification such as sweepers and jingles)'),
    },
    {
        value: 'promo',
        label: $gettext('Promo (station promotion that is not considered an ID)'),
    },
    {
        value: 'ad',
        label: $gettext('Ad (advert replacement files)'),
    },
];

export const formatMediaType = (
    type: string | null | undefined,
    $gettext: (msg: string) => string,
): string => {
    const match = getMediaTypeOptions($gettext).find((opt) => opt.value === type);
    if (match) {
        return match.label.split(' (')[0];
    }

    if (!type) {
        return '';
    }

    return type.charAt(0).toUpperCase() + type.slice(1);
};

export const formatMediaCategory = (
    categoryId: number | null | undefined,
    categoryName: string | null | undefined,
    categories: {id: number; name: string}[],
): string => {
    if (categoryName) {
        return categoryName;
    }

    if (categoryId == null) {
        return '';
    }

    return categories.find((c) => c.id === categoryId)?.name ?? '';
};
