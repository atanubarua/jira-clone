<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\MembershipStatus;
use App\Enums\WorkspaceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'required_without:status',
                'string',
                // Owner is accepted here because promoting to owner IS the
                // transfer flow - the controller gates it behind the separate
                // transferOwnership capability.
                Rule::in(array_column(WorkspaceRole::cases(), 'value')),
            ],
            'status' => [
                'required_without:role',
                'string',
                Rule::in(array_column(MembershipStatus::cases(), 'value')),
            ],
        ];
    }
}
