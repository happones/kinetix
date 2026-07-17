/**
 * Build a blank repeater item from a sub-schema's field defaults, recursing
 * through layout nodes (grid/section/…) that only carry a nested `schema`.
 */
export function buildBlankItem(schema: any[]): Record<string, any> {
    const item: Record<string, any> = {};

    const walk = (nodes: any[]): void => {
        for (const node of nodes) {
            if (Array.isArray(node.schema)) {
                walk(node.schema);
                continue;
            }

            if (node.name) {
                item[node.name] = node.defaultValue ?? null;
            }
        }
    };

    walk(schema);

    return item;
}

export interface UseKinetixRepeaterOptions {
    /** Reactive getter for the form's value map. */
    values: () => Record<string, any>;
    /** Emit an updated array for a named repeater field. */
    emit: (name: string, value: Record<string, any>[]) => void;
}

export interface UseKinetixRepeater {
    itemsOf: (name: string) => Record<string, any>[];
    addItem: (name: string, schema: any[]) => void;
    removeItem: (name: string, index: number) => void;
    moveItem: (name: string, index: number, direction: number) => void;
    updateItem: (
        name: string,
        index: number,
        fieldName: string,
        value: any,
    ) => void;
}

/**
 * Add/remove/reorder/update helpers for the inline repeater fields hosted by a
 * form schema. Keyed by field name so a single schema can host several
 * repeaters; each operation emits the next array, keeping the parent form the
 * single source of truth.
 */
export function useKinetixRepeater(
    options: UseKinetixRepeaterOptions,
): UseKinetixRepeater {
    const itemsOf = (name: string): Record<string, any>[] =>
        Array.isArray(options.values()[name]) ? options.values()[name] : [];

    const addItem = (name: string, schema: any[]): void => {
        options.emit(name, [...itemsOf(name), buildBlankItem(schema)]);
    };

    const removeItem = (name: string, index: number): void => {
        const next = [...itemsOf(name)];
        next.splice(index, 1);
        options.emit(name, next);
    };

    const moveItem = (name: string, index: number, direction: number): void => {
        const next = [...itemsOf(name)];
        const target = index + direction;

        if (target < 0 || target >= next.length) {
            return;
        }

        [next[index], next[target]] = [next[target], next[index]];
        options.emit(name, next);
    };

    const updateItem = (
        name: string,
        index: number,
        fieldName: string,
        value: any,
    ): void => {
        const next = [...itemsOf(name)];
        next[index] = { ...next[index], [fieldName]: value };
        options.emit(name, next);
    };

    return { itemsOf, addItem, removeItem, moveItem, updateItem };
}
