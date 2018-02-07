<?php

use Guc\App;

class AppTest extends PHPUnit_Framework_TestCase {

    public function testGetDB() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with('USE `testwiki_p`;')
            ->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->once())->method('openDB')
            // 'eg1' is expanded
            ->with('eg1.web.db.svc.eqiad.wmflabs', 'testwiki_p')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'testwiki')
        );
    }

    public function testGetDBWithFullHost() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();

        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('prepare')
            ->with('USE `testwiki_p`;')
            ->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->once())->method('openDB')
            // 'eg1.example' is not expanded
            ->with('eg1.example', 'testwiki_p')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'testwiki')
        );
    }

    public static function provideGetDbInvalidHosts() {
        return [
            'array $host' => [
              [ 'eg1' ]
            ],
            'int $host' => [
              123
            ],
            'bool $host' => [
              false
            ],
            'null $host' => [
              null
            ]
        ];
    }

    /**
     * @dataProvider provideGetDbInvalidHosts
     */
    public function testGetDbInvalidHosts($host) {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();

        $app->expects($this->never())->method('openDB');

        $this->expectException(Exception::class);
        $app->getDB($host, 'testwiki');
    }

    public function testGetDBCached() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.web.db.svc.eqiad.wmflabs', 'testwiki_p'],
                ['eg2.web.db.svc.eqiad.wmflabs', 'otherwiki_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'testwiki'),
            'First on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'otherwiki'),
            'Second on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg2', 'otherwiki'),
            'First on eg2'
        );
    }

    public function testGetDbReopenClosedDB() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.web.db.svc.eqiad.wmflabs', 'wikione_a_p'],
                // wikione_b: Use cached eg1 connection
                // wikione_c: Re-open eg1 connection after close
                ['eg1.web.db.svc.eqiad.wmflabs', 'wikione_c_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'wikione_a'),
            'A on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'wikione_b'),
            'B on eg1'
        );

        $app->closeDB('eg1');

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1', 'wikione_c'),
            'C on eg1'
        );
    }

    public function testCloseAllDBs() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['eg1.example', 'wikione_a_p'],
                // Re-open connection after close
                ['eg1.example', 'wikione_b_p']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'wikione_a'),
            'A on eg1'
        );

        $app->closeAllDBs();

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('eg1.example', 'wikione_b'),
            'C on eg1'
        );
    }
}
