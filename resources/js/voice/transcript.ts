/**
 * The two voice lines on screen: what the person is saying now and what the
 * assistant is answering. There is no history: each new exchange replaces the
 * previous one.
 */
export type Transcript = {
    heard: string;
    reply: string;
    /** The person's current utterance is still receiving words. */
    heardOpen: boolean;
    /** The assistant's current answer is still receiving words. */
    replyOpen: boolean;
};

export type TranscriptAction =
    | { type: 'user'; text: string }
    | { type: 'assistant'; text: string }
    | { type: 'turn-ended' }
    | { type: 'reset' };

export const emptyTranscript: Transcript = { heard: '', reply: '', heardOpen: false, replyOpen: false };

export function transcriptReducer(state: Transcript, action: TranscriptAction): Transcript {
    switch (action.type) {
        case 'user':
            return state.heardOpen
                ? { ...state, heard: state.heard + action.text }
                : { heard: action.text.trimStart(), reply: '', heardOpen: true, replyOpen: false };
        case 'assistant':
            return state.replyOpen
                ? { ...state, reply: state.reply + action.text }
                : { ...state, reply: action.text.trimStart(), replyOpen: true };
        case 'turn-ended':
            return { ...state, heardOpen: false, replyOpen: false };
        case 'reset':
            return emptyTranscript;
    }
}
