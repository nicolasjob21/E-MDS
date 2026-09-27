import list from '../../../config/pcg-units.json';

/**
 * The PCG unit list, grouped under its headings — imported from config/pcg-units.json, the one
 * place it is defined (the server validates against the same file). Never copy it into a page.
 */
export interface PcgUnitGroup {
    label: string;
    units: string[];
}

export const PCG_UNIT_GROUPS: readonly PcgUnitGroup[] = list.groups;

const ALL = new Set(PCG_UNIT_GROUPS.flatMap((g) => g.units));

export function isPcgUnit(name: string | null | undefined): boolean {
    return name != null && ALL.has(name);
}
