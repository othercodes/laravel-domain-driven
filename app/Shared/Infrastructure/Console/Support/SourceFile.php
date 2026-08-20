<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Console\Support;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Class SourceFile
 *
 * Answers questions about a PHP file for the scaffolding commands.
 *
 * They used to ask by searching the text, which cannot tell a call from a
 * mention of the same name in a comment, a docblock or a string. That is not
 * a theoretical difference: a mapping somebody had parked behind // was
 * enough to refuse a command outright.
 *
 * Class names come back fully qualified, resolved through the file's own
 * imports, so a comparison never has to guess which of the two forms was
 * written.
 *
 * @author Unay Santisteban <usantisteban@othercode.io>
 */
final class SourceFile
{
    private static ?Parser $parser = null;

    /** @var array<int, Node> */
    private readonly array $ast;

    private readonly NodeFinder $finder;

    private readonly bool $parsed;

    /**
     * A file that does not parse answers no to everything, and says so
     * through parsed().
     *
     * The difference matters. Answering "no" is right for a hint, which at
     * worst repeats advice; it is wrong for a guard against writing something
     * twice, where "I could not read it" and "it is not there" lead to
     * opposite decisions.
     */
    private function __construct(string $code)
    {
        $this->finder = new NodeFinder;

        try {
            $ast = self::parser()->parse($code) ?? [];
            $parsed = true;
        } catch (Error) {
            $ast = [];
            $parsed = false;
        }

        $this->parsed = $parsed;

        $traverser = new NodeTraverser(new NameResolver);

        $this->ast = $traverser->traverse($ast);
    }

    public static function at(string $path): self
    {
        return new self(is_file($path) ? (string) file_get_contents($path) : '');
    }

    public static function of(string $code): self
    {
        return new self($code);
    }

    /**
     * Whether the file could be read at all. A missing file parses fine and
     * simply holds nothing; only a syntax error answers false.
     */
    public function parsed(): bool
    {
        return $this->parsed;
    }

    /**
     * Whether any method with this name is called anywhere in the file.
     */
    public function calls(string $method): bool
    {
        return $this->finder->findFirst(
            $this->ast,
            fn (Node $node): bool => ($node instanceof Node\Expr\MethodCall
                || $node instanceof Node\Expr\NullsafeMethodCall
                || $node instanceof Node\Expr\StaticCall)
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $method
        ) !== null;
    }

    /**
     * Whether the file declares a class by this short name.
     *
     * Asked before anything is concluded from what a file does not contain.
     * A file that fails to parse and one that holds nothing both answer no to
     * every other question here, and "no such method" read off either of them
     * is not a fact about the class, it is the absence of the class.
     */
    public function declaresClass(string $name): bool
    {
        return $this->finder->findFirst(
            $this->ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\Class_
                && $node->name !== null
                && $node->name->toString() === $name
        ) !== null;
    }

    /**
     * Whether a class in the file declares this method.
     */
    /**
     * The tables a migration creates in up(), as named in its Schema::create calls.
     *
     * Asked of the parsed source rather than of the text, for the reason this
     * file gives everywhere else: a Schema::create parked behind // is not a
     * table anybody creates, and neither is one quoted inside a string. A
     * migration that does not parse answers with nothing, which is safe here
     * because migrate is already broken and says so far more loudly.
     *
     * @return list<string>
     */
    public function createdTables(): array
    {
        // Only what up() creates. A reversible drop migration restores its
        // table in down(), and counting that answered with a table the file
        // exists to remove: the guard then refused an aggregate over a table
        // no migration creates, and wrote nothing at all, naming a drop
        // migration as the owner.
        $up = $this->finder->findFirst(
            $this->ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === 'up'
        );

        if (! $up instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $calls = $this->finder->find(
            $up->stmts ?? [],
            fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->class instanceof Node\Name
                && $node->class->toString() === 'Illuminate\Support\Facades\Schema'
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'create'
        );

        $tables = [];

        foreach ($calls as $call) {
            $first = $call->args[0] ?? null;

            if ($first instanceof Node\Arg && $first->value instanceof Node\Scalar\String_) {
                $tables[] = $first->value->value;
            }
        }

        return $tables;
    }

    public function declaresMethod(string $method): bool
    {
        return $this->finder->findFirst(
            $this->ast,
            fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod
                && $node->name->toString() === $method
        ) !== null;
    }

    /**
     * Whether the file names this class anywhere it counts as a reference.
     */
    public function references(string $class): bool
    {
        return $this->finder->findFirst(
            $this->ast,
            fn (Node $node): bool => $node instanceof Node\Expr\ClassConstFetch
                && $node->class instanceof Node\Name
                && $node->class->toString() === ltrim($class, '\\')
        ) !== null;
    }

    /**
     * Whether a class in the file implements this interface.
     *
     * An implements clause is a name, not a ::class reference, so references()
     * never sees it.
     */
    public function implementsInterface(string $interface): bool
    {
        return $this->finder->findFirst(
            $this->ast,
            function (Node $node) use ($interface): bool {
                if (! $node instanceof Node\Stmt\Class_) {
                    return false;
                }

                foreach ($node->implements as $name) {
                    if ($name->toString() === ltrim($interface, '\\')) {
                        return true;
                    }
                }

                return false;
            }
        ) !== null;
    }

    /**
     * The literal string a property is initialised with, e.g. $table.
     */
    public function propertyString(string $property): ?string
    {
        $default = $this->propertyDefault($property);

        return $default instanceof Node\Scalar\String_ ? $default->value : null;
    }

    /**
     * The class names used as keys of an array property, e.g. $events.
     *
     * @return list<string>
     */
    public function propertyKeys(string $property): array
    {
        return $this->arrayItems($property, fn (Node\ArrayItem $item): ?string => $item->key instanceof Node\Expr\ClassConstFetch
            && $item->key->class instanceof Node\Name
                ? $item->key->class->toString()
                : null);
    }

    /**
     * The string parts of an array property's values, which is how the
     * migration paths are written: __DIR__.'/Invoices/…/Migrations'.
     *
     * @return list<string>
     */
    public function propertyStrings(string $property): array
    {
        $default = $this->propertyDefault($property);

        return $default instanceof Node\Expr\Array_ ? $this->strings($default) : [];
    }

    /**
     * Every string an array literal holds, however deeply.
     *
     * $routes is keyed by middleware group, so its paths sit one level in,
     * and reading only the top level answered "declares nothing" for every
     * provider there is. That is why $routes was the one declarative array no
     * command checked: not a decision, an empty answer nobody questioned.
     *
     * @return list<string>
     */
    private function strings(Node\Expr\Array_ $array): array
    {
        $found = [];

        foreach ($array->items as $item) {
            $value = $item->value;

            if ($value instanceof Node\Scalar\String_) {
                $found[] = $value->value;

                continue;
            }

            // __DIR__.'/path', which is how every declared path is written.
            if ($value instanceof Node\Expr\BinaryOp\Concat && $value->right instanceof Node\Scalar\String_) {
                $found[] = $value->right->value;

                continue;
            }

            if ($value instanceof Node\Expr\Array_) {
                $found = [...$found, ...$this->strings($value)];
            }
        }

        return $found;
    }

    /**
     * The class names listed in the array the file returns, which is the
     * shape of bootstrap/providers.php.
     *
     * @return list<string>
     */
    public function returnedClasses(): array
    {
        $return = $this->finder->findFirstInstanceOf($this->ast, Node\Stmt\Return_::class);

        if (! $return?->expr instanceof Node\Expr\Array_) {
            return [];
        }

        $classes = [];

        foreach ($return->expr->items as $item) {
            if ($item->value instanceof Node\Expr\ClassConstFetch && $item->value->class instanceof Node\Name) {
                $classes[] = $item->value->class->toString();
            }
        }

        return $classes;
    }

    /**
     * @param  callable(Node\ArrayItem): ?string  $read
     * @return list<string>
     */
    private function arrayItems(string $property, callable $read): array
    {
        $default = $this->propertyDefault($property);

        if (! $default instanceof Node\Expr\Array_) {
            return [];
        }

        $found = [];

        foreach ($default->items as $item) {
            if (($value = $read($item)) !== null) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * One statement can declare several properties, as in `protected array
     * $a = [], $b = [];`, so the one asked for is not always the first.
     */
    private function propertyDefault(string $property): ?Node\Expr
    {
        foreach ($this->finder->findInstanceOf($this->ast, Node\Stmt\Property::class) as $statement) {
            foreach ($statement->props as $declared) {
                if ($declared->name->toString() === $property) {
                    return $declared->default;
                }
            }
        }

        return null;
    }

    private static function parser(): Parser
    {
        return self::$parser ??= (new ParserFactory)->createForNewestSupportedVersion();
    }
}
