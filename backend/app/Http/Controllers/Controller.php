<?php
namespace App\Http\Controllers;

abstract class Controller
{
    protected function scoped(string $resource, int $id)
    {
        $config = config("erp.resources.$resource");
        return \App\Support\ResourceScope::apply($config['model']::query(), $config)->findOrFail($id);
    }
    protected function warehouse(int $id): void
    {
        $this->scoped('warehouses', $id);
    }
}
