<?php

use App\Connectors\Issues\FakeIssueTracker;
use App\Connectors\Issues\IssueTracker;
use App\Connectors\Jira\JiraRules;
use App\Models\User;
use App\Voice\AssistantProfile;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->app->instance(IssueTracker::class, $this->jira = new FakeIssueTracker);
    $this->user = User::factory()->create(['name' => 'John León']);
});

function runJiraTool(string $tool, array $arguments = []): TestResponse
{
    return test()->actingAs(test()->user)->postJson(route('voice.tools.run', $tool), ['arguments' => $arguments, 'timezone' => 'Europe/Madrid']);
}

function confirmJira(TestResponse $proposal): void
{
    test()->actingAs(test()->user)->postJson(route('actions.confirm', $proposal->json('output.pending_action_id')))->assertOk();
}

test('issues are found and shown numbered', function () {
    $response = runJiraTool('search_jira', ['text' => 'pasarela'])
        ->assertJsonPath('canvas.view', 'openui.view')
        ->assertJsonPath('canvas.props.items.0', ['n' => 1, 'title' => 'CPD-405 Integrar la pasarela de pago', 'tool' => 'open_jira_issue', 'args' => ['issue' => 'demo:CPD-405']])
        ->assertJsonPath('output.issues.0.key', 'CPD-405')
        ->assertJsonPath('output.issues.0.issue_id', 'demo:CPD-405');

    expect($response->json('canvas.props.ui'))->toContain('eyebrow: "CPD-405 · Story", status: "In Progress"')->toBeValidOpenUi();
});

test('a search brings up to the limit asked for, and says when there are more', function () {
    runJiraTool('search_jira', ['limit' => 2])
        ->assertJsonCount(2, 'output.issues')
        ->assertJsonPath('output.more', true)
        ->assertJsonPath('output.note', 'Hay más de 2: si los quiere todos, vuelve a buscar con un limit mayor o afina la consulta.');

    runJiraTool('search_jira')->assertJsonPath('output.more', null)->assertJsonPath('output.note', null);
});

test('an issue opens with its dependencies and its comments, numbered', function () {
    runJiraTool('open_jira_issue', ['issue' => 'CPD-412'])
        ->assertJsonPath('canvas.view', 'jira.issue')
        ->assertJsonPath('canvas.props.comments.1.mentions_me', true)
        ->assertJsonPath('output.parent', 'CPD-400 (Epic): Pagar la reserva por adelantado')
        ->assertJsonPath('output.dependencies.0', 'CPD-412 is blocked by CPD-405 (In Progress) · link_id demo:501')
        ->assertJsonPath('output.comments.1.comment_id', 'demo:CPD-412:10002');
});

test('a new story is a draft until confirmed, with a type and priority the site has', function () {
    $proposal = runJiraTool('propose_jira_issue', ['project' => 'CPD', 'type' => 'story', 'summary' => 'As a diner, I want to pay in advance', 'description' => "## Context\nPrepago", 'parent' => 'CPD-400', 'priority' => 'high', 'assignee' => 'Marta'])
        ->assertJsonPath('canvas.view', 'jira.draft')
        ->assertJsonPath('canvas.props.issue.type', 'Story')
        ->assertJsonPath('canvas.props.issue.priority', 'High')
        ->assertJsonPath('canvas.props.issue.assignee', 'Marta Ruiz');

    expect($this->jira->writes)->toBe([]);

    confirmJira($proposal);

    expect($this->jira->writes[0][0])->toBe('create')
        ->and($this->jira->writes[0][1])->toMatchArray(['projectKey' => 'CPD', 'type' => 'Story', 'parentKey' => 'CPD-400', 'priority' => 'High', 'assigneeId' => 'u-marta']);
});

test('what the site does not have is sent back to the assistant', function (array $arguments, string $error) {
    runJiraTool('propose_jira_issue', ['project' => 'CPD', 'summary' => 'X', ...$arguments])
        ->assertJsonPath('canvas', null)
        ->assertJsonPath('output.error', fn (string $message) => str_contains($message, $error));
})->with([
    'unknown type' => [['type' => 'Bug'], 'no tiene el tipo «Bug»'],
    'subtask without parent' => [['type' => 'Subtask'], 'necesita la clave de su issue padre'],
    'unknown priority' => [['type' => 'Task', 'priority' => 'Urgentísima'], 'No existe la prioridad'],
]);

test('changing the priority shows before and after, and only changes once confirmed', function () {
    $proposal = runJiraTool('propose_jira_update', ['issue' => 'CPD-412', 'priority' => 'Highest'])
        ->assertJsonPath('canvas.props.changes.0', ['label' => 'Prioridad', 'before' => 'Medium', 'after' => 'Highest']);

    confirmJira($proposal);

    expect($this->jira->writes[0])->toBe(['update', ['ref' => 'demo:CPD-412', 'summary' => null, 'description' => null, 'priority' => 'Highest', 'labels' => null, 'assigneeId' => null, 'parentKey' => null, 'components' => null]]);

    runJiraTool('propose_jira_update', ['issue' => 'CPD-412', 'priority' => 'Medium'])->assertJsonPath('output.error', fn (string $error) => str_contains($error, 'ya está así'));
});

test('dependencies are marked and removed once confirmed', function () {
    confirmJira(runJiraTool('propose_jira_dependency', ['action' => 'add', 'blocker' => 'CPD-405', 'blocked' => 'CPD-400'])
        ->assertJsonPath('canvas.props.dependency.relation', 'bloquea a'));
    confirmJira(runJiraTool('propose_jira_dependency', ['action' => 'remove', 'issue' => 'CPD-412', 'link_id' => 'demo:501']));

    expect($this->jira->writes)->toBe([
        ['link', ['blocker' => 'demo:CPD-405', 'blocked' => 'demo:CPD-400']],
        ['unlink', ['link' => 'demo:501']],
    ]);
});

test('an answer to a comment quotes it and mentions its author', function () {
    $proposal = runJiraTool('propose_jira_comment', ['issue' => 'CPD-412', 'comment' => 'Entra en este sprint.', 'reply_to' => '2'])
        ->assertJsonPath('canvas.props.in_reply_to.author', 'Pablo Gil')
        ->assertJsonPath('canvas.props.mentions', ['Pablo Gil']);

    confirmJira($proposal);

    expect($this->jira->writes[0][1]['markdown'])->toBe("> @John ¿nos ayudas a decidir si entra en este sprint?\n\n@Pablo Gil Entra en este sprint.")
        ->and($this->jira->writes[0][1]['mentions'])->toBe(['Pablo Gil' => 'u-pablo']);
});

test('the assistant starts each conversation knowing the jira rules', function () {
    $profile = AssistantProfile::for($this->user, CarbonImmutable::now(), issues: $this->jira);

    expect($profile->instructions)->toContain('Reglas de Jira')->toContain('Como <rol>, quiero <acción> para <beneficio>');
});

test('a new issue takes the components the rules give every issue, or those said, as the project writes them', function () {
    $this->app->instance(IssueTracker::class, $this->jira = new class extends FakeIssueTracker
    {
        public function rules(User $user): array
        {
            return [['account_id' => 0, 'site' => 'covermanager', 'rules' => new JiraRules("## Proyectos y filtros\n- Proyectos: CPD\n- Componentes: coverexperiences")]];
        }
    });

    $byRules = runJiraTool('propose_jira_issue', ['project' => 'CPD', 'type' => 'Task', 'summary' => 'Añadir las keys de seats.io'])
        ->assertJsonPath('canvas.props.issue.components', ['coverexperiences']);
    confirmJira($byRules);

    expect($this->jira->writes[0][1]['components'])->toBe(['coverexperiences']);

    runJiraTool('propose_jira_issue', ['project' => 'CPD', 'type' => 'Task', 'summary' => 'Otra', 'components' => ['pagos']])
        ->assertJsonPath('canvas.props.issue.components', ['Pagos']);

    runJiraTool('propose_jira_issue', ['project' => 'CPD', 'type' => 'Task', 'summary' => 'Otra', 'components' => ['facturas']])
        ->assertJsonPath('output.error', 'El proyecto CPD no tiene el componente «facturas». Tiene: coverexperiences, Pagos, Reservas.');
});

test('an issue\'s components can be changed', function () {
    $proposal = runJiraTool('propose_jira_update', ['issue' => 'CPD-412', 'components' => ['coverexperiences', 'reservas']])
        ->assertJsonPath('canvas.props.changes.0', ['label' => 'Componentes', 'before' => null, 'after' => 'coverexperiences, Reservas']);
    confirmJira($proposal);

    expect($this->jira->writes[0][1]['components'])->toBe(['coverexperiences', 'Reservas']);
});
