<?php

namespace App\Http\Resources\MQTT;

use stdClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SuccessResource extends JsonResource
{
    protected ?string $explicitMethod = null;

    public function __construct($resource, ?string $method = null)
    {
        parent::__construct($resource);
        $this->explicitMethod = $method;
    }

    public static function make(...$parameters)
    {
        return new static(...$parameters);
    }

    public function toArray(Request $request): array
    {
        $data = new stdClass();
        $id = $this->resource->id ?? null;

        return [
            'code' => 200,
            'data' => $data,
            'id' => is_scalar($id) ? (string) $id : '',
            'message' => 'success',
            'method' => $this->explicitMethod ?? $this->resource->method ?? null,
            'version' => '1.0',
        ];
    }
}
