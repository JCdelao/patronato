import { useCallback } from 'react';

export type CleanupFn = () => void;

export function useMobileNavigation(): CleanupFn {
    return useCallback(() => {
        // Quita pointer-events del body
        document.body.style.removeProperty('pointer-events');
    }, []);
}
