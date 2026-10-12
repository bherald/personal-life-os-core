<?php

namespace App\Services\Ops;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class LogMessageSourceAuditService
{
    /** @var list<string> */
    private const LOG_METHODS = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency',
        'log',
    ];

    /** @var list<string> */
    private const LOGGER_FACTORY_METHODS = [
        'channel',
        'driver',
        'stack',
        'build',
    ];

    /** @var list<string> */
    private const PRIVATE_EXCEPTION_METHODS = [
        '__tostring',
        'getfile',
        'getmessage',
        'gettrace',
        'gettraceasstring',
    ];

    private readonly Parser $parser;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
    }

    /**
     * @return array<string, int|string>
     */
    public function scanApplication(): array
    {
        $aggregate = $this->emptyResult();

        foreach ([app_path(), base_path('routes')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($file->getPathname());
                if ($source === false) {
                    $aggregate['php_files_read_failed']++;

                    continue;
                }

                $aggregate['php_files_scanned']++;
                $aggregate = $this->merge($aggregate, $this->analyzeCode($source));
            }
        }

        return $aggregate;
    }

    /**
     * Analyze one PHP source body without retaining paths or message content.
     *
     * @return array<string, int|string>
     */
    public function analyzeCode(string $source): array
    {
        $result = $this->emptyResult();

        try {
            $nodes = $this->parser->parse($source);
        } catch (Error) {
            $result['php_files_parse_failed'] = 1;

            return $result;
        }

        if ($nodes === null) {
            $result['php_files_parse_failed'] = 1;

            return $result;
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver);
        $nodes = $traverser->traverse($nodes);

        $this->walk($nodes, function (Node $node) use (&$result): void {
            $call = $this->logCall($node);
            if ($call === null) {
                return;
            }

            $result['log_calls']++;
            $result[$call['surface'].'_calls']++;

            $classification = $this->classifyMessage($call['message']);
            $result[$classification.'_message_sites']++;
        });

        return $result;
    }

    /**
     * @return array{surface: string, message: Expr}|null
     */
    private function logCall(Node $node): ?array
    {
        if ($node instanceof StaticCall && $this->isLogFacade($node->class)) {
            $method = $this->identifier($node->name);
            if ($method === null || ! in_array($method, self::LOG_METHODS, true)) {
                return null;
            }

            $messageIndex = $method === 'log' ? 1 : 0;
            $message = $node->args[$messageIndex]->value ?? null;

            return $message instanceof Expr
                ? ['surface' => 'facade_static', 'message' => $message]
                : null;
        }

        if ($node instanceof MethodCall) {
            $method = $this->identifier($node->name);
            if ($method === null || ! in_array($method, self::LOG_METHODS, true) || ! $this->isLoggerReceiver($node->var)) {
                return null;
            }

            $messageIndex = $method === 'log' ? 1 : 0;
            $message = $node->args[$messageIndex]->value ?? null;

            return $message instanceof Expr
                ? [
                    'surface' => $this->isFacadeLoggerReceiver($node->var)
                        ? 'facade_channel'
                        : ($this->isLoggerHelperReceiver($node->var) ? 'logger_helper' : 'logger_instance'),
                    'message' => $message,
                ]
                : null;
        }

        if ($node instanceof FuncCall && $this->functionName($node) === 'logger') {
            $message = $node->args[0]->value ?? null;

            return $message instanceof Expr
                ? ['surface' => 'logger_helper', 'message' => $message]
                : null;
        }

        return null;
    }

    private function classifyMessage(Expr $message): string
    {
        if ($this->containsPrivateExceptionDetail($message)) {
            return 'direct_exception_detail';
        }

        if ($this->containsPsrPlaceholder($message)) {
            return 'placeholder_template';
        }

        if ($this->isLiteralMessage($message)) {
            return 'literal';
        }

        if ($this->isBoundedLiteralChoice($message)) {
            return 'bounded_literal_choice';
        }

        return 'dynamic';
    }

    private function isLiteralMessage(Expr $expression): bool
    {
        if ($expression instanceof String_) {
            return true;
        }

        if ($expression instanceof Concat) {
            return $this->isLiteralMessage($expression->left)
                && $this->isLiteralMessage($expression->right);
        }

        if ($expression instanceof InterpolatedString) {
            foreach ($expression->parts as $part) {
                if (! $part instanceof InterpolatedStringPart) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function containsPsrPlaceholder(Expr $expression): bool
    {
        $literal = $this->literalMessageValue($expression);
        if ($literal !== null) {
            return preg_match('/\{[A-Za-z0-9_.]+\}/', $literal) === 1;
        }

        if ($expression instanceof Ternary) {
            return ($expression->if instanceof Expr && $this->containsPsrPlaceholder($expression->if))
                || $this->containsPsrPlaceholder($expression->else);
        }

        if ($expression instanceof Match_) {
            foreach ($expression->arms as $arm) {
                if ($this->containsPsrPlaceholder($arm->body)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function literalMessageValue(Expr $expression): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof Concat) {
            $left = $this->literalMessageValue($expression->left);
            $right = $this->literalMessageValue($expression->right);

            return $left !== null && $right !== null ? $left.$right : null;
        }

        if ($expression instanceof InterpolatedString) {
            $value = '';
            foreach ($expression->parts as $part) {
                if (! $part instanceof InterpolatedStringPart) {
                    return null;
                }

                $value .= $part->value;
            }

            return $value;
        }

        return null;
    }

    private function isBoundedLiteralChoice(Expr $expression): bool
    {
        if ($expression instanceof Ternary) {
            return $expression->if instanceof Expr
                && $this->isLiteralOrBoundedChoice($expression->if)
                && $this->isLiteralOrBoundedChoice($expression->else);
        }

        if ($expression instanceof Match_) {
            if ($expression->arms === []) {
                return false;
            }

            foreach ($expression->arms as $arm) {
                if (! $this->isLiteralOrBoundedChoice($arm->body)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function isLiteralOrBoundedChoice(Expr $expression): bool
    {
        return $this->isLiteralMessage($expression)
            || $this->isBoundedLiteralChoice($expression);
    }

    private function containsPrivateExceptionDetail(Expr $expression): bool
    {
        if ($expression instanceof Variable) {
            return is_string($expression->name)
                && preg_match('/^(?:e|exception|throwable)$/i', $expression->name) === 1;
        }

        if ($expression instanceof PropertyFetch) {
            $property = $this->identifier($expression->name);
            if ($property !== null && preg_match('/^(?:exception|throwable)$/i', $property) === 1) {
                return true;
            }
        }

        if ($expression instanceof MethodCall) {
            $method = $this->identifier($expression->name);
            if ($method !== null && in_array($method, self::PRIVATE_EXCEPTION_METHODS, true)) {
                return true;
            }

            if ($method === 'getcode') {
                return false;
            }
        }

        if ($expression instanceof Ternary) {
            return ($expression->if instanceof Expr && $this->containsPrivateExceptionDetail($expression->if))
                || $this->containsPrivateExceptionDetail($expression->else);
        }

        if ($expression instanceof Match_) {
            foreach ($expression->arms as $arm) {
                if ($this->containsPrivateExceptionDetail($arm->body)) {
                    return true;
                }
            }

            return false;
        }

        if ($expression instanceof FuncCall) {
            $function = $this->functionName($expression);
            if (in_array($function, ['get_class', 'get_debug_type'], true)) {
                return false;
            }
        }

        foreach ($expression->getSubNodeNames() as $name) {
            $value = $expression->{$name};
            if ($value instanceof Expr && $this->containsPrivateExceptionDetail($value)) {
                return true;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if ($item instanceof Expr && $this->containsPrivateExceptionDetail($item)) {
                    return true;
                }

                if ($item instanceof Arg && $this->containsPrivateExceptionDetail($item->value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isLoggerReceiver(Expr $expression): bool
    {
        if ($this->isFacadeLoggerReceiver($expression) || $this->isLoggerHelperReceiver($expression)) {
            return true;
        }

        if ($expression instanceof Variable && is_string($expression->name)) {
            return preg_match('/logger$/i', $expression->name) === 1;
        }

        if ($expression instanceof PropertyFetch) {
            $property = $this->identifier($expression->name);

            return $property !== null && preg_match('/logger$/i', $property) === 1;
        }

        if ($expression instanceof MethodCall) {
            return $this->isLoggerReceiver($expression->var);
        }

        return false;
    }

    private function isFacadeLoggerReceiver(Expr $expression): bool
    {
        if ($expression instanceof StaticCall && $this->isLogFacade($expression->class)) {
            $method = $this->identifier($expression->name);

            return $method !== null && in_array($method, self::LOGGER_FACTORY_METHODS, true);
        }

        return $expression instanceof MethodCall
            && $this->isFacadeLoggerReceiver($expression->var);
    }

    private function isLoggerHelperReceiver(Expr $expression): bool
    {
        if ($expression instanceof FuncCall && $this->functionName($expression) === 'logger' && $expression->args === []) {
            return true;
        }

        return $expression instanceof MethodCall
            && $this->isLoggerHelperReceiver($expression->var);
    }

    private function isLogFacade(Node $class): bool
    {
        if (! $class instanceof Name) {
            return false;
        }

        $name = ltrim(strtolower($class->toString()), '\\');

        return $name === 'log' || $name === 'illuminate\\support\\facades\\log';
    }

    private function identifier(Node|string $name): ?string
    {
        if ($name instanceof Identifier) {
            return strtolower($name->toString());
        }

        return is_string($name) ? strtolower($name) : null;
    }

    private function functionName(FuncCall $call): ?string
    {
        return $call->name instanceof Name
            ? strtolower($call->name->toString())
            : null;
    }

    /**
     * @param  Node|list<Node>  $nodes
     */
    private function walk(Node|array $nodes, callable $visitor): void
    {
        if ($nodes instanceof Node) {
            $visitor($nodes);

            foreach ($nodes->getSubNodeNames() as $name) {
                $value = $nodes->{$name};
                if ($value instanceof Node || is_array($value)) {
                    $this->walk($value, $visitor);
                }
            }

            return;
        }

        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $this->walk($node, $visitor);
            }
        }
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyResult(): array
    {
        return [
            'schema' => 'plos.log_message_source_audit.v1',
            'engine' => 'nikic_php_parser_ast_v5',
            'php_files_scanned' => 0,
            'php_files_read_failed' => 0,
            'php_files_parse_failed' => 0,
            'log_calls' => 0,
            'literal_message_sites' => 0,
            'placeholder_template_message_sites' => 0,
            'bounded_literal_choice_message_sites' => 0,
            'dynamic_message_sites' => 0,
            'direct_exception_detail_message_sites' => 0,
            'facade_static_calls' => 0,
            'facade_channel_calls' => 0,
            'logger_helper_calls' => 0,
            'logger_instance_calls' => 0,
        ];
    }

    /**
     * @param  array<string, int|string>  $left
     * @param  array<string, int|string>  $right
     * @return array<string, int|string>
     */
    private function merge(array $left, array $right): array
    {
        foreach ($right as $key => $value) {
            if (! is_int($value)) {
                continue;
            }

            $left[$key] = (int) ($left[$key] ?? 0) + (int) $value;
        }

        return $left;
    }
}
