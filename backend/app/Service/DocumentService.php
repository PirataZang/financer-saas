<?php

namespace App\Service;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Project;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Document templates. The editor (frontend DocumentEditor) saves HTML with {{Group.field}} variables;
 * emitting a document fills them from the chosen customer / project / company and renders a PDF.
 */
class DocumentService
{
    /**
     * Every variable the editor offers (key => label), in menu order. The frontend loads this list from
     * GET /documents/variables, so adding one = a line here + its value in variables().
     */
    public const VARIABLES = [
        'Cliente.name' => 'Cliente - Nome',
        'Cliente.email' => 'Cliente - E-mail',
        'Cliente.phone' => 'Cliente - Telefone',
        'Cliente.address' => 'Cliente - Endereço completo',
        'Cliente.zip_code' => 'Cliente - CEP',
        'Cliente.street' => 'Cliente - Rua',
        'Cliente.number' => 'Cliente - Número',
        'Cliente.complement' => 'Cliente - Complemento',
        'Cliente.neighborhood' => 'Cliente - Bairro',
        'Cliente.city' => 'Cliente - Cidade',
        'Cliente.state' => 'Cliente - UF',
        'Projeto.name' => 'Projeto - Nome',
        'Projeto.description' => 'Projeto - Descrição',
        'Projeto.status' => 'Projeto - Situação',
        'Projeto.start_date' => 'Projeto - Início',
        'Projeto.due_date' => 'Projeto - Prazo',
        'Empresa.name' => 'Empresa - Razão social',
        'Empresa.trade_name' => 'Empresa - Nome fantasia',
        'Empresa.document' => 'Empresa - CPF/CNPJ',
        'Empresa.address' => 'Empresa - Endereço completo',
        'Valor' => 'Valor (informado na emissão)',
        'Data' => 'Data de hoje (da emissão)',
    ];

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return Document::query()
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->whereLike('name', "%{$search}%"))
            ->when(isset($filters['active']), fn ($q) => $q->where('active', filter_var($filters['active'], FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    public function index(int $id): Document
    {
        return Document::findOrFail($id);
    }

    public function create(array $data): Document
    {
        if (array_key_exists('content', $data)) {
            $data['content'] = $this->sanitizeHtml($data['content']);
        }

        return Document::create($data);
    }

    public function update(int $id, array $data): Document
    {
        $document = Document::findOrFail($id);

        if (array_key_exists('content', $data)) {
            $data['content'] = $this->sanitizeHtml($data['content']);
        }

        $document->update($data);

        return $document;
    }

    public function delete(int $id): bool
    {
        return Document::findOrFail($id)->delete();
    }

    public function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:25'],
            'active' => ['sometimes', 'boolean'],
            'content' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function emitRules(): array
    {
        return [
            'customer_id' => ['sometimes', 'nullable', 'integer', Rule::exists('customers', 'id')->where('company_id', Company::current())->whereNull('deleted_at')],
            'project_id' => ['sometimes', 'nullable', 'integer', Rule::exists('projects', 'id')->where('company_id', Company::current())->whereNull('deleted_at')],
            'value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * Strip anything the editor's toolbar can't itself produce (script tags, event-handler attributes...)
     * before saving: the content is later replayed both into the browser (v-html) and into dompdf, so this
     * is the one place that has to hold the line against injected HTML/JS.
     */
    private function sanitizeHtml(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Cache.DefinitionImpl', null);
        $config->set(
            'HTML.Allowed',
            'p,h1,h2,h3,blockquote,b,strong,i,em,u,s,strike,ul,ol,li,hr,br,'.
            'a[href],img[src],span[style],font[color],div[style|align]'
        );
        $config->set('CSS.AllowedProperties', ['color', 'background-color', 'text-align']);
        // data: is needed for images pasted straight into the editor (stored as base64).
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);

        return (new HTMLPurifier($config))->purify($html);
    }

    /**
     * Renders a document to PDF for download, filling its variables from the linked customer / project
     * (and the company, the value and today's date).
     *
     * @param  array{customer_id?: ?int, project_id?: ?int, value?: ?string}  $params
     * @return array{pdf: PdfDocument, filename: string}
     */
    public function emit(int $id, array $params = []): array
    {
        $document = $this->index($id);

        return [
            'pdf' => Pdf::loadHTML($this->render($document, $params))->setPaper('a4'),
            'filename' => Str::slug($document->name ?: 'documento').'.pdf',
        ];
    }

    /**
     * The final HTML (variables filled, print styles around it). A variable that is in the document but
     * has no data (a {{Cliente.name}} with no customer chosen) stops the emission instead of printing
     * the raw token.
     *
     * @throws ValidationException inactive document, or variables without data
     */
    public function render(Document $document, array $params = []): string
    {
        if (!$document->active) {
            throw ValidationException::withMessages(['active' => ['Somente documentos ativos podem ser emitidos.']]);
        }

        $customer = !empty($params['customer_id']) ? Customer::find($params['customer_id']) : null;
        $project = !empty($params['project_id']) ? Project::find($params['project_id']) : null;
        $values = $this->variables($customer, $project, $params['value'] ?? null);
        $content = $document->content ?? '';

        $missing = collect(self::VARIABLES)
            ->filter(fn ($label, $key) => !array_key_exists($key, $values) && str_contains($content, '{{'.$key.'}}'));
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages([
                'variables' => ['Faltam dados para: '.$missing->implode(', ').'. Escolha o cliente/projeto ou informe o valor.'],
            ]);
        }

        return $this->toPdfHtml($document, $this->replaceVariables($content, $values));
    }

    /**
     * The {{Key}} => text map for what was given (a key missing here = no data for it).
     *
     * @return array<string, ?string>
     */
    private function variables(?Customer $customer, ?Project $project, ?string $value): array
    {
        $values = ['Data' => now()->format('d/m/Y')];

        if ($customer) {
            $values += [
                'Cliente.name' => $customer->name,
                'Cliente.email' => $customer->email,
                'Cliente.phone' => $customer->phone,
                'Cliente.address' => $this->address($customer),
                'Cliente.zip_code' => $customer->zip_code,
                'Cliente.street' => $customer->street,
                'Cliente.number' => $customer->number,
                'Cliente.complement' => $customer->complement,
                'Cliente.neighborhood' => $customer->neighborhood,
                'Cliente.city' => $customer->city,
                'Cliente.state' => $customer->state,
            ];
        }

        if ($project) {
            $values += [
                'Projeto.name' => $project->name,
                'Projeto.description' => $project->description,
                'Projeto.status' => $project->status?->label(),
                'Projeto.start_date' => $project->start_date?->format('d/m/Y'),
                'Projeto.due_date' => $project->due_date?->format('d/m/Y'),
            ];
        }

        if ($company = Company::find(Company::current())) {
            $values += [
                'Empresa.name' => $company->name,
                'Empresa.trade_name' => $company->trade_name,
                'Empresa.document' => $company->document,
                'Empresa.address' => $this->address($company),
            ];
        }

        if ($value !== null && $value !== '') {
            $values['Valor'] = 'R$ '.number_format((float) $value, 2, ',', '.');
        }

        return $values;
    }

    /** "Rua A, 10, Centro, São Paulo - SP, 01000-000" from whatever the record has. */
    private function address(Customer|Company $record): string
    {
        $street = trim("{$record->street}, {$record->number}", ', ');
        $city = trim("{$record->city} - {$record->state}", ' -');

        return collect([$street, $record->neighborhood, $city, $record->zip_code])->filter()->implode(', ');
    }

    /** Every {{Key}} replaced by its value, HTML-escaped so a variable's text is shown literally, never as markup. */
    private function replaceVariables(string $html, array $values): string
    {
        $search = [];
        $replace = [];

        foreach ($values as $key => $value) {
            $search[] = '{{'.$key.'}}';
            $replace[] = e($value ?? '');
        }

        return str_replace($search, $replace, $html);
    }

    /** The editor's HTML inside the print styles. dompdf doesn't resolve CSS custom properties, so colors are literal. */
    private function toPdfHtml(Document $document, string $content): string
    {
        $title = e($document->name);

        return <<<HTML
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="utf-8">
                <title>{$title}</title>
                <style>
                    body { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; color: #0f172a; line-height: 1.6; }
                    h1 { font-size: 22px; font-weight: 700; margin: 0 0 12px; }
                    h2 { font-size: 18px; font-weight: 700; margin: 0 0 10px; }
                    h3 { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
                    p { margin: 0 0 12px; }
                    blockquote { margin: 0 0 12px; padding-left: 16px; border-left: 3px solid #cbd5e1; color: #475569; }
                    a { color: #4f46e5; }
                </style>
            </head>
            <body>{$content}</body>
            </html>
            HTML;
    }
}
