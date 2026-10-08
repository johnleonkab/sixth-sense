import AlertReview from '@/canvas/alerts/alert-review';
import AlertsList from '@/canvas/alerts/alerts-list';
import CalendarAgenda from '@/canvas/calendar/calendar-agenda';
import CalendarChange from '@/canvas/calendar/calendar-change';
import CalendarDay from '@/canvas/calendar/calendar-day';
import CanvasFrame from '@/canvas/canvas-frame';
import DocsComment from '@/canvas/docs/docs-comment';
import DocsDocument from '@/canvas/docs/docs-document';
import DocsEdit from '@/canvas/docs/docs-edit';
import DocsResults from '@/canvas/docs/docs-results';
import MailDraft from '@/canvas/mail/mail-draft';
import ImageViewer from '@/canvas/files/image-viewer';
import PdfViewer from '@/canvas/files/pdf-viewer';
import Media from '@/canvas/media';
import SlackConversation from '@/canvas/slack/slack-conversation';
import SlackDraft from '@/canvas/slack/slack-draft';
import SlackResults from '@/canvas/slack/slack-results';
import SlackUnread from '@/canvas/slack/slack-unread';
import MailMessage from '@/canvas/mail/mail-message';
import MailResults from '@/canvas/mail/mail-results';
import type { CanvasComponent, CanvasDirective } from '@/canvas/types';

/**
 * Every canvas the assistant can put on screen, by the name Laravel uses.
 * To add one: build the component and register it here.
 */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const canvases: Record<string, CanvasComponent<any>> = {
    'calendar.day': CalendarDay,
    'calendar.agenda': CalendarAgenda,
    'calendar.change': CalendarChange,
    'mail.results': MailResults,
    'mail.message': MailMessage,
    'mail.draft': MailDraft,
    'docs.results': DocsResults,
    'docs.document': DocsDocument,
    'docs.edit': DocsEdit,
    'docs.comment': DocsComment,
    'slack.unread': SlackUnread,
    'slack.conversation': SlackConversation,
    'slack.results': SlackResults,
    'slack.draft': SlackDraft,
    media: Media,
    'pdf.viewer': PdfViewer,
    'image.viewer': ImageViewer,
    'alerts.list': AlertsList,
    'alerts.review': AlertReview,
};

export function Canvas({ directive, index }: { directive: CanvasDirective; index: number }) {
    const Component = canvases[directive.view];

    if (!Component) {
        return (
            <CanvasFrame index={index} title="No sé mostrar esto todavía" source={directive.view}>
                <p className="text-[13px] text-muted">Falta el canvas «{directive.view}».</p>
            </CanvasFrame>
        );
    }

    return <Component {...directive.props} index={index} />;
}
