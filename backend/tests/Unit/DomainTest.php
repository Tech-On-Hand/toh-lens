<?php

namespace Tests\Unit;

use App\Support\Domain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainTest extends TestCase
{
    #[DataProvider('accepted')]
    public function test_it_reduces_input_to_a_bare_hostname(string $input, string $expected): void
    {
        $this->assertSame($expected, Domain::normalize($input));
    }

    public static function accepted(): array
    {
        return [
            'plain' => ['youtube.com', 'youtube.com'],
            'url with path and query' => ['https://www.YouTube.com/watch?v=1#t', 'www.youtube.com'],
            'wildcard' => ['*.example.org', 'example.org'],
            'leading dot' => ['.example.org', 'example.org'],
            'credentials and port' => ['http://user:pw@example.com:8080/x', 'example.com'],
            'trailing dot' => ['example.com.', 'example.com'],
            'whitespace and case' => ["  Khan.Example.COM \n", 'khan.example.com'],
            'punycode tld' => ['example.xn--p1ai', 'example.xn--p1ai'],
        ];
    }

    #[DataProvider('rejected')]
    public function test_it_rejects_things_that_are_not_domains(string $input): void
    {
        $this->assertNull(Domain::normalize($input));
    }

    public static function rejected(): array
    {
        return [
            'empty' => [''],
            'single label' => ['localhost'],
            'ipv4' => ['192.168.1.1'],
            'words' => ['not a domain'],
            'empty label' => ['a..b.com'],
            'leading hyphen' => ['-x.com'],
            'one letter tld' => ['example.c'],
            'script scheme' => ['javascript:alert(1)'],
            'too long' => [str_repeat('a', 250).'.com'],
        ];
    }

    public function test_a_rule_covers_the_domain_and_its_subdomains_only(): void
    {
        $this->assertTrue(Domain::matches('example.com', 'example.com'));
        $this->assertTrue(Domain::matches('a.b.example.com', 'example.com'));
        $this->assertTrue(Domain::matches('EXAMPLE.com', 'example.com'));
        $this->assertFalse(Domain::matches('notexample.com', 'example.com'));
        $this->assertFalse(Domain::matches('example.com.evil.net', 'example.com'));
    }
}
