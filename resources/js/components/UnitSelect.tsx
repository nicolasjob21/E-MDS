import type { SelectHTMLAttributes } from 'react';
import { PCG_UNIT_GROUPS, isPcgUnit } from '../lib/pcgUnits';

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'value' | 'onChange' | 'children'> & {
    value: string;
    onChange: (unit: string) => void;
    /** The first option's text; "" is sent when it is chosen. */
    placeholder?: string;
};

/**
 * Every Unit field's dropdown: a blank "-- Select unit --" first, then the PCG units under their
 * headings (non-selectable optgroups), in list order. The value is the unit's name exactly as
 * the list spells it.
 *
 * A saved unit that is not on the list (from before the list existed) is shown as a disabled
 * option, so an edit form still says what is on file — but it cannot be chosen again.
 */
export default function UnitSelect({ value, onChange, placeholder = '-- Select unit --', className = 'field', ...rest }: Props) {
    const legacy = value !== '' && !isPcgUnit(value);

    return (
        <select {...rest} className={className} value={value} onChange={(e) => onChange(e.target.value)}>
            <option value="">{placeholder}</option>
            {legacy && (
                <option value={value} disabled>
                    {value} (not on the unit list — choose another)
                </option>
            )}
            {PCG_UNIT_GROUPS.map((group) => (
                <optgroup key={group.label} label={group.label}>
                    {group.units.map((unit) => (
                        <option key={unit} value={unit}>
                            {unit}
                        </option>
                    ))}
                </optgroup>
            ))}
        </select>
    );
}
