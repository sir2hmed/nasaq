<?php

namespace App\Http\Requests\Workflow;

use App\Services\WorkflowGraphValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

abstract class WorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $identifier = ['string', 'min:3', 'max:64', 'regex:/^[a-z][a-z0-9_-]{2,63}$/'];

        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', Rule::in(['draft', 'active', 'archived'])],
            'graph_json' => ['required', 'array:version,name,description,nodes,edges'],
            'graph_json.version' => ['required', 'integer', 'in:1'],
            'graph_json.name' => ['required', 'string', 'max:160'],
            'graph_json.description' => ['nullable', 'string', 'max:2000'],
            'graph_json.nodes' => ['required', 'array', 'min:1', 'max:100'],
            'graph_json.nodes.*' => ['required', 'array:id,type,position,config'],
            'graph_json.nodes.*.id' => ['required', ...$identifier, 'distinct:strict'],
            'graph_json.nodes.*.type' => [
                'required',
                Rule::in(['researcher', 'writer', 'export', 'video', 'approval', 'publisher', 'email']),
            ],
            'graph_json.nodes.*.position' => ['required', 'array:x,y'],
            'graph_json.nodes.*.position.x' => ['required', 'numeric', 'between:-100000,100000'],
            'graph_json.nodes.*.position.y' => ['required', 'numeric', 'between:-100000,100000'],
            'graph_json.nodes.*.config' => ['present', 'array'],
            'graph_json.edges' => ['present', 'array', 'max:300'],
            'graph_json.edges.*' => ['required', 'array:id,source,target'],
            'graph_json.edges.*.id' => ['required', ...$identifier, 'distinct:strict'],
            'graph_json.edges.*.source' => ['required', ...$identifier],
            'graph_json.edges.*.target' => ['required', ...$identifier, 'different:graph_json.edges.*.source'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $result = app(WorkflowGraphValidator::class)->validate($this->input('graph_json'));
            foreach ($result['errors'] as $error) {
                $validator->errors()->add('graph_json', $error['message']);
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'description' => $this->normalizeDescription($this->input('description')),
            'status' => $this->input('status', 'draft'),
        ]);
    }

    private function normalizeDescription(mixed $description): mixed
    {
        if (! is_string($description)) {
            return $description;
        }

        $description = trim($description);

        return $description === '' ? null : $description;
    }
}
