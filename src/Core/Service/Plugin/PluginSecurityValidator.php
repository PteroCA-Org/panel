<?php

namespace App\Core\Service\Plugin;

use App\Core\DTO\PluginSecurityCheckResultDTO;
use App\Core\Entity\Plugin;
use App\Core\Service\Composer\ComposerBinaryResolverService;
use ArrayIterator;
use Exception;
use Iterator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

/**
 * Security validator for plugin code.
 *
 * Performs static analysis to detect:
 * - Dangerous PHP functions (eval, exec, shell_exec, etc.)
 * - Path traversal attempts (../)
 * - Potential SQL injection patterns
 * - XSS vulnerabilities
 * - File permission issues
 *
 * Returns array of security issues with severity levels:
 * - critical: Must be fixed before plugin can be enabled
 * - high: Should be fixed, creates security risk
 * - medium: Potential issue, review recommended
 * - low: Best practice suggestion
 */
class PluginSecurityValidator
{
    private const SEVERITY_CRITICAL = 'critical';
    private const SEVERITY_HIGH = 'high';
    private const SEVERITY_MEDIUM = 'medium';
    private const SEVERITY_LOW = 'low';

    private readonly string $composerBinary;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ComposerDependencyManager $composerManager,
        private readonly ComposerBinaryResolverService $composerResolver,
        #[Autowire(param: 'plugin_security.dangerous_functions')]
        private readonly array $dangerousFunctions,
        #[Autowire(param: 'plugin_security.checks')]
        private readonly array $securityChecks,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
        $this->composerBinary = $this->composerResolver->resolveComposerBinary();
    }

    /**
     * Validate plugin security.
     *
     * @param Plugin $plugin Plugin to validate
     * @return PluginSecurityCheckResultDTO Security check results with all checks and issues
     * @throws Exception
     */
    public function validate(Plugin $plugin): PluginSecurityCheckResultDTO
    {
        $allIssues = [];
        $checks = [];

        $this->logger->info("Running security validation for plugin", [
            'plugin' => $plugin->getName(),
        ]);

        // Check 1: Dangerous functions
        if ($this->securityChecks['dangerous_functions'] ?? true) {
            $dangerousFunctionIssues = $this->scanForDangerousFunctions($plugin->getPath());
            $allIssues = array_merge($allIssues, $dangerousFunctionIssues);
            $checks['dangerous_functions'] = empty($dangerousFunctionIssues);
        }

        // Check 2: Path traversal
        if ($this->securityChecks['path_traversal'] ?? true) {
            $pathTraversalIssues = $this->scanForPathTraversal($plugin->getPath());
            $allIssues = array_merge($allIssues, $pathTraversalIssues);
            $checks['path_traversal'] = empty($pathTraversalIssues);
        }

        // Check 3: SQL injection patterns
        if ($this->securityChecks['sql_injection'] ?? true) {
            $sqlInjectionIssues = $this->analyzeDatabaseQueries($plugin->getPath());
            $allIssues = array_merge($allIssues, $sqlInjectionIssues);
            $checks['sql_injection'] = empty($sqlInjectionIssues);
        }

        // Check 4: XSS patterns
        if ($this->securityChecks['xss_patterns'] ?? true) {
            $xssIssues = $this->scanForXSSPatterns($plugin->getPath());
            $allIssues = array_merge($allIssues, $xssIssues);
            $checks['xss_patterns'] = empty($xssIssues);
        }

        // Check 5: File permissions
        if ($this->securityChecks['file_permissions'] ?? true) {
            $permissionIssues = $this->checkFilePermissions($plugin->getPath());
            $allIssues = array_merge($allIssues, $permissionIssues);
            $checks['file_permissions'] = empty($permissionIssues);
        }

        // Check 6: Composer dependencies security
        if ($this->securityChecks['composer_dependencies'] ?? true) {
            $composerIssues = $this->validateComposerDependencies($plugin);
            $allIssues = array_merge($allIssues, $composerIssues);
            $checks['composer_dependencies'] = empty($composerIssues);
        }

        $criticalCount = count(array_filter($allIssues, fn($i) => $i['severity'] === self::SEVERITY_CRITICAL));
        $highCount = count(array_filter($allIssues, fn($i) => $i['severity'] === self::SEVERITY_HIGH));

        $this->logger->info("Security validation completed", [
            'plugin' => $plugin->getName(),
            'total_issues' => count($allIssues),
            'critical' => $criticalCount,
            'high' => $highCount,
        ]);

        // Return DTO with all checks and issues
        if (empty($allIssues)) {
            return PluginSecurityCheckResultDTO::secure($plugin, $checks);
        }

        return PluginSecurityCheckResultDTO::insecure($plugin, $checks, $allIssues);
    }

    /**
     * Scan for dangerous PHP functions.
     * @throws Exception
     */
    public function scanForDangerousFunctions(string $pluginPath): array
    {
        $issues = [];

        foreach ($this->scanPhpFiles($pluginPath) as $file) {
            $content = file_get_contents($file->getRealPath());
            $lines = explode("\n", $content);

            foreach ($this->dangerousFunctions as $function) {
                $pattern = '/\b' . preg_quote($function, '/') . '\s*\(/';

                foreach ($lines as $lineNumber => $line) {
                    // Skip comments
                    if (preg_match('/^\s*(\/\/|#|\*)/', $line)) {
                        continue;
                    }

                    if (preg_match($pattern, $line)) {
                        $issues[] = [
                            'type' => 'dangerous_function',
                            'severity' => $this->getFunctionSeverity($function),
                            'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                            'line' => $lineNumber + 1,
                            'message' => sprintf("Use of dangerous function '%s()' detected", $function),
                            'suggestion' => $this->getSuggestionForFunction($function),
                            'code_snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Scan for path traversal attempts.
     * @throws Exception
     */
    public function scanForPathTraversal(string $pluginPath): array
    {
        $issues = [];

        $patterns = [
            '/\.\.[\/\\\\]/' => 'Path traversal pattern "../" detected',
            '/\$_(?:GET|POST|REQUEST|COOKIE)\[[\'"].*[\'"]]\s*\.\s*[\'"][\/\\\\]/' => 'User input used in file path without sanitization',
        ];

        foreach ($this->scanPhpFiles($pluginPath) as $file) {
            $content = file_get_contents($file->getRealPath());
            $lines = explode("\n", $content);

            foreach ($patterns as $pattern => $message) {
                foreach ($lines as $lineNumber => $line) {
                    if (preg_match($pattern, $line)) {
                        $issues[] = [
                            'type' => 'path_traversal',
                            'severity' => self::SEVERITY_HIGH,
                            'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                            'line' => $lineNumber + 1,
                            'message' => $message,
                            'suggestion' => 'Use realpath() or basename() to sanitize file paths',
                            'code_snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Analyze database queries for potential SQL injection.
     * @throws Exception
     */
    public function analyzeDatabaseQueries(string $pluginPath): array
    {
        $issues = [];

        $patterns = [
            '/->query\([^\?].*\$/' => 'Direct variable interpolation in SQL query',
            '/execute\([\'"](?:SELECT|INSERT|UPDATE|DELETE).*\$/' => 'Variable concatenation in SQL query',
            '/\$[a-zA-Z_]+\s*\.\s*[\'"](?:SELECT|INSERT|UPDATE|DELETE)/' => 'SQL query built with string concatenation',
        ];

        foreach ($this->scanPhpFiles($pluginPath) as $file) {
            $content = file_get_contents($file->getRealPath());
            $lines = explode("\n", $content);

            foreach ($patterns as $pattern => $message) {
                foreach ($lines as $lineNumber => $line) {
                    // Skip if using prepared statements (safe)
                    if (preg_match('/prepare|setParameter|bindValue|bindParam/', $line)) {
                        continue;
                    }

                    if (preg_match($pattern, $line)) {
                        $issues[] = [
                            'type' => 'sql_injection',
                            'severity' => self::SEVERITY_CRITICAL,
                            'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                            'line' => $lineNumber + 1,
                            'message' => $message,
                            'suggestion' => 'Use prepared statements with parameter binding',
                            'code_snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Scan for XSS vulnerability patterns.
     * @throws Exception
     */
    public function scanForXSSPatterns(string $pluginPath): array
    {
        $issues = [];

        $patterns = [
            '/echo\s+\$_(?:GET|POST|REQUEST|COOKIE)/' => 'Direct output of user input without escaping',
            '/print\s+\$_(?:GET|POST|REQUEST|COOKIE)/' => 'Direct output of user input without escaping',
            '/<\?=\s*\$_(?:GET|POST|REQUEST|COOKIE)/' => 'Direct output of user input in short tag',
        ];

        foreach ($this->scanPhpFiles($pluginPath) as $file) {
            $content = file_get_contents($file->getRealPath());
            $lines = explode("\n", $content);

            foreach ($patterns as $pattern => $message) {
                foreach ($lines as $lineNumber => $line) {
                    // Skip if using escaping functions (safe)
                    if (preg_match('/htmlspecialchars|htmlentities|escape|e\(/', $line)) {
                        continue;
                    }

                    if (preg_match($pattern, $line)) {
                        $issues[] = [
                            'type' => 'xss',
                            'severity' => self::SEVERITY_HIGH,
                            'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                            'line' => $lineNumber + 1,
                            'message' => $message,
                            'suggestion' => 'Use htmlspecialchars() or Twig auto-escaping',
                            'code_snippet' => trim($line),
                        ];
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * Check file permissions.
     * @throws Exception
     */
    public function checkFilePermissions(string $pluginPath): array
    {
        $issues = [];

        foreach ($this->scanPhpFiles($pluginPath) as $file) {
            $perms = fileperms($file->getRealPath());
            $octal = substr(sprintf('%o', $perms), -4);

            // Check if file is world-writable (dangerous)
            if (($perms & 0x0002) !== 0) {
                $issues[] = [
                    'type' => 'file_permissions',
                    'severity' => self::SEVERITY_HIGH,
                    'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                    'line' => 0,
                    'message' => sprintf("File is world-writable (permissions: %s)", $octal),
                    'suggestion' => 'Set file permissions to 644 or 640',
                    'code_snippet' => '',
                ];
            }

            // Check if file is executable (suspicious for .php files)
            if (($perms & 0x0040) !== 0) {
                $issues[] = [
                    'type' => 'file_permissions',
                    'severity' => self::SEVERITY_LOW,
                    'file' => $this->getRelativePath($file->getRealPath(), $pluginPath),
                    'line' => 0,
                    'message' => sprintf("PHP file is executable (permissions: %s)", $octal),
                    'suggestion' => 'PHP files should not be executable, set to 644',
                    'code_snippet' => '',
                ];
            }
        }

        return $issues;
    }

    /**
     * Get PHP files from plugin directory.
     * @throws Exception
     */
    private function scanPhpFiles(string $directory): Iterator
    {
        if (!is_dir($directory)) {
            return new ArrayIterator([]);
        }

        $finder = new Finder();
        $finder->files()
            ->in($directory)
            ->name('*.php')
            ->exclude(['vendor', 'node_modules', 'tests'])
            ->ignoreVCS(true);

        return $finder->getIterator();
    }

    /**
     * Get relative path for display.
     */
    private function getRelativePath(string $fullPath, string $basePath): string
    {
        if (str_starts_with($fullPath, $basePath)) {
            return substr($fullPath, strlen($basePath) + 1);
        }

        return $fullPath;
    }

    /**
     * Get severity level for dangerous function.
     */
    private function getFunctionSeverity(string $function): string
    {
        return match ($function) {
            'eval', 'exec', 'shell_exec', 'system', 'passthru' => self::SEVERITY_CRITICAL,
            'proc_open', 'popen', 'pcntl_exec' => self::SEVERITY_HIGH,
            default => self::SEVERITY_MEDIUM,
        };
    }

    /**
     * Get suggestion for dangerous function.
     */
    private function getSuggestionForFunction(string $function): string
    {
        return match ($function) {
            'eval' => 'Avoid eval(). Consider using safer alternatives or refactoring code',
            'exec', 'shell_exec', 'system', 'passthru' => 'Avoid shell commands. Use Symfony Process component with proper input validation',
            'proc_open', 'popen' => 'Use Symfony Process component instead',
            'assert' => 'Do not use assert() with string arguments in production',
            default => 'Consider using safer alternatives',
        };
    }

    /**
     * Validate Composer dependencies for security issues.
     *
     * Performs comprehensive validation:
     * 1. Check for forbidden sections (scripts, allow-plugins)
     * 2. Verify composer.lock exists
     * 3. Run composer validate
     * 4. Check manifest declaration matches composer.json
     * 5. Verify PHP version compatibility
     * 6. Run security audit (composer audit)
     * 7. Check licenses (optional warning)
     *
     * @param Plugin $plugin Plugin to validate
     * @return array List of security issues
     */
    private function validateComposerDependencies(Plugin $plugin): array
    {
        $issues = [];
        $pluginPath = $this->projectDir . '/plugins/' . $plugin->getName();
        $composerJsonPath = $pluginPath . '/composer.json';

        // Skip if plugin doesn't have composer.json
        if (!$this->composerManager->hasComposerJson($plugin)) {
            return [];
        }

        // Load composer.json
        $composerData = json_decode(file_get_contents($composerJsonPath), true);

        if ($composerData === null) {
            return [[
                'type' => 'composer_invalid_json',
                'severity' => self::SEVERITY_CRITICAL,
                'message' => 'composer.json is not valid JSON',
                'file' => 'composer.json',
            ]];
        }

        // Validation 1: Check for forbidden sections
        if (isset($composerData['scripts']) && !empty($composerData['scripts'])) {
            $issues[] = [
                'type' => 'composer_scripts_forbidden',
                'severity' => self::SEVERITY_CRITICAL,
                'message' => 'Plugin composer.json contains forbidden "scripts" section (security risk)',
                'file' => 'composer.json',
                'suggestion' => 'Remove "scripts" section from composer.json',
            ];
        }

        if (isset($composerData['config']['allow-plugins'])) {
            $issues[] = [
                'type' => 'composer_plugins_forbidden',
                'severity' => self::SEVERITY_CRITICAL,
                'message' => 'Plugin composer.json contains forbidden "allow-plugins" config (security risk)',
                'file' => 'composer.json',
                'suggestion' => 'Remove "config.allow-plugins" from composer.json',
            ];
        }

        // Validation 2: Check composer.lock existence
        if (!$this->composerManager->hasComposerLock($plugin)) {
            $issues[] = [
                'type' => 'composer_lock_missing',
                'severity' => self::SEVERITY_HIGH,
                'message' => 'Plugin is missing composer.lock file (required for reproducible builds)',
                'file' => 'composer.lock',
                'suggestion' => 'Run "composer install" in plugin directory and commit composer.lock',
            ];
        }

        // Validation 3: Run composer validate
        $process = new Process(
            [$this->composerBinary, 'validate', '--no-check-publish', '--strict'],
            $pluginPath,
            $this->composerManager->buildProcessEnvironment()
        );
        $process->run();

        if (!$process->isSuccessful()) {
            $issues[] = [
                'type' => 'composer_validate_failed',
                'severity' => self::SEVERITY_HIGH,
                'message' => 'composer.json validation failed',
                'file' => 'composer.json',
                'details' => trim($process->getErrorOutput()),
                'suggestion' => 'Fix composer.json structure errors',
            ];
        }

        // Validation 4: Check manifest declaration matches composer.json
        $manifest = $plugin->getManifest();
        $declaredDeps = $manifest['composer_dependencies'] ?? null;
        $actualDeps = $composerData['require'] ?? [];

        // Remove php constraint from comparison
        $actualDepsForComparison = $actualDeps;
        unset($actualDepsForComparison['php']);

        if ($declaredDeps !== null && $declaredDeps !== $actualDepsForComparison) {
            $issues[] = [
                'type' => 'composer_declaration_mismatch',
                'severity' => self::SEVERITY_HIGH,
                'message' => 'composer_dependencies in plugin.json does not match require section in composer.json',
                'file' => 'plugin.json / composer.json',
                'suggestion' => 'Ensure both files declare the same dependencies',
            ];
        }

        // Validation 5: Check PHP version compatibility
        $phpConstraint = $actualDeps['php'] ?? null;
        if ($phpConstraint !== null) {
            // Simple version check - full semver validation would require composer/semver library
            $currentPhp = PHP_VERSION;
            $constraintSimple = str_replace(['>=', '^', '~', ' '], '', $phpConstraint);

            if (version_compare($currentPhp, $constraintSimple, '<')) {
                $issues[] = [
                    'type' => 'php_version_incompatible',
                    'severity' => self::SEVERITY_CRITICAL,
                    'message' => sprintf('Plugin requires PHP %s, current: %s', $phpConstraint, $currentPhp),
                    'file' => 'composer.json',
                    'suggestion' => 'Update PHP version or adjust composer.json requirement',
                ];
            }
        }

        // Validation 6: Run security audit (if vendor/ exists)
        if ($this->composerManager->hasVendorDirectory($plugin)) {
            $auditProcess = new Process(
                [$this->composerBinary, 'audit', '--format=json', '--no-dev'],
                $pluginPath,
                $this->composerManager->buildProcessEnvironment(),
                timeout: 60
            );
            $auditProcess->run();

            if (!$auditProcess->isSuccessful()) {
                try {
                    $auditData = json_decode($auditProcess->getOutput(), true);
                    $advisories = $auditData['advisories'] ?? [];

                    if (!empty($advisories)) {
                        // Count by severity
                        $criticalVulns = [];
                        $highVulns = [];

                        foreach ($advisories as $packageAdvisories) {
                            foreach ($packageAdvisories as $advisory) {
                                $severity = strtolower($advisory['severity'] ?? 'unknown');

                                if ($severity === 'critical') {
                                    $criticalVulns[] = $advisory;
                                } elseif ($severity === 'high') {
                                    $highVulns[] = $advisory;
                                }
                            }
                        }

                        // Add issues for vulnerabilities
                        if (!empty($criticalVulns)) {
                            $issues[] = [
                                'type' => 'composer_critical_vulnerabilities',
                                'severity' => self::SEVERITY_CRITICAL,
                                'message' => sprintf('%d critical security vulnerabilities found in dependencies', count($criticalVulns)),
                                'file' => 'composer.lock',
                                'suggestion' => 'Run "composer update" to fix vulnerabilities, then run "plugin:install-deps --clean"',
                            ];
                        }

                        if (!empty($highVulns)) {
                            $issues[] = [
                                'type' => 'composer_high_vulnerabilities',
                                'severity' => self::SEVERITY_HIGH,
                                'message' => sprintf('%d high severity vulnerabilities found in dependencies', count($highVulns)),
                                'file' => 'composer.lock',
                                'suggestion' => 'Run "composer update" to fix vulnerabilities',
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to parse composer audit output', [
                        'plugin' => $plugin->getName(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Validation 7: Check licenses (optional - low severity warning)
        $allowedLicenses = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'MPL-2.0', 'ISC', 'LGPL-3.0-or-later'];
        $pluginLicense = $composerData['license'] ?? null;

        if ($pluginLicense && !in_array($pluginLicense, $allowedLicenses, true)) {
            $issues[] = [
                'type' => 'unknown_license',
                'severity' => self::SEVERITY_LOW,
                'message' => sprintf('Plugin license "%s" is not in the standard allowlist', $pluginLicense),
                'file' => 'composer.json',
                'suggestion' => 'Verify license compatibility with your project',
            ];
        }

        return $issues;
    }
}
