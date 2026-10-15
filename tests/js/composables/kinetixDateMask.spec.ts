import { describe, expect, it } from 'vitest';
import {
    applyDateMask,
    dateMaskToIso,
    defaultDateFormat,
    isoToDateMask,
    maskPlaceholder,
    parseDateFormat,
} from '@/composables/kinetixDateMask';

const mask = (format: string) => parseDateFormat(format);

describe('kinetixDateMask', () => {
    it('reads "mm" as the month in the date and the minutes in the time', () => {
        expect(mask('dd/mm/yyyy HH:mm').map((segment) => segment.type)).toEqual(
            [
                'day',
                'literal',
                'month',
                'literal',
                'year',
                'literal',
                'hour',
                'literal',
                'minute',
            ],
        );
        expect(maskPlaceholder(mask('dd/mm/yyyy HH:mm'))).toBe(
            'DD/MM/YYYY HH:MM',
        );
    });

    it('inserts the separators as the digits come', () => {
        const segments = mask('dd/mm/yyyy');

        expect(applyDateMask('1', segments)).toBe('1');
        expect(applyDateMask('15', segments)).toBe('15');
        expect(applyDateMask('150', segments)).toBe('15/0');
        expect(applyDateMask('15032026', segments)).toBe('15/03/2026');
        expect(applyDateMask('15/03/2026999', segments)).toBe('15/03/2026');
        expect(applyDateMask('ab15-03', segments)).toBe('15/03');
    });

    it('pads a first digit no two-digit value can start with', () => {
        const segments = mask('dd/mm/yyyy');

        expect(applyDateMask('4', segments)).toBe('04');
        expect(applyDateMask('42', segments)).toBe('04/02');
        expect(applyDateMask('3', segments)).toBe('3');
        expect(applyDateMask('0', segments)).toBe('0');
        expect(applyDateMask('9', mask('HH:mm'))).toBe('09');
        expect(applyDateMask('127', mask('HH:mm'))).toBe('12:07');
    });

    it('turns a complete text into the ISO value, in any order', () => {
        expect(dateMaskToIso('15/03/2026', mask('dd/mm/yyyy'), 'date')).toBe(
            '2026-03-15',
        );
        expect(dateMaskToIso('03/15/2026', mask('mm/dd/yyyy'), 'date')).toBe(
            '2026-03-15',
        );
        expect(dateMaskToIso('2026-03-15', mask('yyyy-mm-dd'), 'date')).toBe(
            '2026-03-15',
        );
        expect(dateMaskToIso('15.03.26', mask('dd.mm.yy'), 'date')).toBe(
            '2026-03-15',
        );
        expect(
            dateMaskToIso(
                '15/03/2026 09:30',
                mask('dd/mm/yyyy HH:mm'),
                'datetime',
            ),
        ).toBe('2026-03-15T09:30');
        expect(dateMaskToIso('07:05 PM', mask('hh:mm a'), 'time')).toBe(
            '19:05',
        );
        expect(dateMaskToIso('12:00 AM', mask('hh:mm a'), 'time')).toBe(
            '00:00',
        );
        expect(dateMaskToIso('03/2026', mask('mm/yyyy'), 'month')).toBe(
            '2026-03',
        );
    });

    it('waits for an unfinished text and refuses a date that does not exist', () => {
        expect(
            dateMaskToIso('15/03/20', mask('dd/mm/yyyy'), 'date'),
        ).toBeNull();
        expect(dateMaskToIso('', mask('dd/mm/yyyy'), 'date')).toBeNull();
        expect(dateMaskToIso('31/02/2026', mask('dd/mm/yyyy'), 'date')).toBe(
            false,
        );
        expect(dateMaskToIso('29/02/2028', mask('dd/mm/yyyy'), 'date')).toBe(
            '2028-02-29',
        );
        expect(dateMaskToIso('10/13/2026', mask('dd/mm/yyyy'), 'date')).toBe(
            false,
        );
        expect(dateMaskToIso('24:00', mask('HH:mm'), 'time')).toBe(false);
    });

    it('shows an ISO value in the mask', () => {
        expect(isoToDateMask('2026-03-15', mask('dd/mm/yyyy'))).toBe(
            '15/03/2026',
        );
        expect(
            isoToDateMask('2026-03-15T19:05', mask('mm/dd/yyyy hh:mm a')),
        ).toBe('03/15/2026 07:05 PM');
        expect(isoToDateMask('09:30', mask('HH:mm'))).toBe('09:30');
        expect(isoToDateMask('2026-03', mask('mm/yyyy'))).toBe('03/2026');
        expect(isoToDateMask(null, mask('dd/mm/yyyy'))).toBe('');
    });

    it('defaults to the order the locale writes dates in', () => {
        expect(defaultDateFormat('date', 'es-MX')).toBe('dd/mm/yyyy');
        expect(defaultDateFormat('date', 'en-US')).toBe('mm/dd/yyyy');
        expect(defaultDateFormat('date', 'de-DE')).toBe('dd.mm.yyyy');
        expect(defaultDateFormat('month', 'es-MX')).toBe('mm/yyyy');
        expect(defaultDateFormat('time', 'es-ES')).toBe('HH:mm');
        expect(defaultDateFormat('time', 'en-US')).toBe('hh:mm a');
        // The field's own clock wins over the locale's.
        expect(defaultDateFormat('time', 'en-US', false)).toBe('HH:mm');
        expect(defaultDateFormat('datetime', 'es-ES', true)).toBe(
            'dd/mm/yyyy hh:mm a',
        );
    });
});
