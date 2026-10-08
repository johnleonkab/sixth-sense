/**
 * Recognises the person's answer to a proposed change in what they said.
 * This runs in the browser, on the person's own words: the assistant never
 * confirms anything, so text inside a document or an email can not either.
 */
export type Answer = 'yes' | 'no' | 'undo';

const normalise = (text: string): string =>
    text
        .toLowerCase()
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[¿?¡!.,;:"«»]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

const UNDO = /\b(deshaz|deshazlo|deshacer|revierte|revertir|vuelve atras|marcha atras)\b/;
const YES = /^(si|vale|ok|okay|adelante|hazlo|dale|confirmo|confirma|confirmado|aplicalo|aplica|aplicar|perfecto|correcto|de acuerdo|publicalo|publica)\b/;
const NO = /^(no|cancela|cancelalo|cancelar|dejalo|olvidalo|mejor no|para)\b/;

export function answerIn(text: string): Answer | null {
    const words = normalise(text);

    if (UNDO.test(words)) {
        return 'undo';
    }

    if (YES.test(words)) {
        return 'yes';
    }

    if (NO.test(words)) {
        return 'no';
    }

    return null;
}
