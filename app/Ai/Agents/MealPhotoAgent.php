<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[MaxTokens(1500)]
class MealPhotoAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Identify food visible in this meal photo. Return a short meal title and up to 12 visible dishes or ingredients. Estimate grams only when possible. Mark uncertain portions and ingredients uncertain. Never invent hidden ingredients, nutrient values, medical advice, or identity details.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'items' => $schema->array()->min(1)->max(12)->items($schema->object([
                'name' => $schema->string()->required(),
                'grams' => $schema->number()->min(1)->max(2000)->nullable()->required(),
                'uncertain' => $schema->boolean()->required(),
                'note' => $schema->string()->required(),
            ])->withoutAdditionalProperties())->required(),
        ];
    }
}
