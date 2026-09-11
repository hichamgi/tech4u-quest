<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container
{
    /** @var array<class-string,object> */
    private array $instances = [];

    public function set(string $id, object $instance): void
    {
        $this->instances[$id] = $instance;
    }

    public function get(string $id): object
    {
        if ($id === PDO::class) {
            return Database::connection();
        }
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }
        if (!class_exists($id)) {
            throw new RuntimeException('Dépendance introuvable : ' . $id);
        }

        $reflection = new ReflectionClass($id);
        if (!$reflection->isInstantiable()) {
            throw new RuntimeException('Dépendance non instanciable : ' . $id);
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            $instance = $reflection->newInstance();
            $this->instances[$id] = $instance;
            return $instance;
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                if ($parameter->isDefaultValueAvailable()) {
                    $arguments[] = $parameter->getDefaultValue();
                    continue;
                }
                throw new RuntimeException(
                    'Impossible de résoudre ' . $id . '::$' . $parameter->getName()
                );
            }
            $arguments[] = $this->get($type->getName());
        }

        $instance = $reflection->newInstanceArgs($arguments);
        $this->instances[$id] = $instance;
        return $instance;
    }
}
