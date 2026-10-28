import AlertReview from '@/canvas/alerts/alert-review';
import AgendaTask from '@/canvas/agenda/agenda-task';
import AlertsHistory from '@/canvas/alerts/alerts-history';
import AlertsList from '@/canvas/alerts/alerts-list';
import CalendarAgenda from '@/canvas/calendar/calendar-agenda';
import CalendarChange from '@/canvas/calendar/calendar-change';
import CalendarDay from '@/canvas/calendar/calendar-day';
import CanvasFrame from '@/canvas/canvas-frame';
import DocsComment from '@/canvas/docs/docs-comment';
import DocsDocument from '@/canvas/docs/docs-document';
import DocsSheet from '@/canvas/docs/docs-sheet';
import DocsSlides from '@/canvas/docs/docs-slides';
import FilesResults from '@/canvas/docs/files-results';
import DocsEdit from '@/canvas/docs/docs-edit';
import DocsResults from '@/canvas/docs/docs-results';
import MailDraft from '@/canvas/mail/mail-draft';
import ImageViewer from '@/canvas/files/image-viewer';
import PdfViewer from '@/canvas/files/pdf-viewer';
import JiraDraft from '@/canvas/jira/jira-draft';
import JiraIssue from '@/canvas/jira/jira-issue';
import JiraList from '@/canvas/jira/jira-list';
import Media from '@/canvas/media';
import NotionNew from '@/canvas/notion/notion-new';
import NotionPage from '@/canvas/notion/notion-page';
import NotionResults from '@/canvas/notion/notion-results';
import SlackConversation from '@/canvas/slack/slack-conversation';
import SlackAutonomy from '@/canvas/slack/slack-autonomy';
import SlackDigest from '@/canvas/slack/slack-digest';
import SlackRule from '@/canvas/slack/slack-rule';
import SlackDraft from '@/canvas/slack/slack-draft';
import SlackResults from '@/canvas/slack/slack-results';
import WingmanDoc from '@/canvas/wingman/wingman-doc';
import SenseFile from '@/canvas/sense/sense-file';
import SceneBoard from '@/canvas/scenes/scene';
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
    'docs.sheet': DocsSheet,
    'docs.slides': DocsSlides,
    'files.results': FilesResults,
    'docs.edit': DocsEdit,
    'docs.comment': DocsComment,
    'slack.unread': SlackUnread,
    'slack.digest': SlackDigest,
    'slack.autonomy': SlackAutonomy,
    'slack.rule': SlackRule,
    'slack.conversation': SlackConversation,
    'slack.results': SlackResults,
    'slack.draft': SlackDraft,
    media: Media,
    'pdf.viewer': PdfViewer,
    'image.viewer': ImageViewer,
    'alerts.list': AlertsList,
    'alerts.review': AlertReview,
    'alerts.history': AlertsHistory,
    'agenda.task': AgendaTask,
    'jira.list': JiraList,
    'jira.issue': JiraIssue,
    'jira.draft': JiraDraft,
    'notion.results': NotionResults,
    'notion.page': NotionPage,
    'notion.new': NotionNew,
    'wingman.doc': WingmanDoc,
    'sense.file': SenseFile,
    'scene.board': SceneBoard,
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
