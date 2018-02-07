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
            ->with('testwiki_p', 'eg1.web.db.svc.eqiad.wmflabs')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('testwiki', 'eg1')
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
            ->with('testwiki_p', 'eg1.example')
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('testwiki', 'eg1.example')
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
        $app->getDB('testwiki', $host);
    }

    public function testGetDBCached() {
        $app = $this->getMockBuilder(App::class)
            ->setMethods(['openDB'])
            ->getMock();
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($this->createMock(PDOStatement::class));

        $app->expects($this->exactly(2))->method('openDB')
            ->withConsecutive(
                ['testwiki_p', 'eg1.web.db.svc.eqiad.wmflabs'],
                ['otherwiki_p', 'eg2.web.db.svc.eqiad.wmflabs']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('testwiki', 'eg1'),
            'First on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('otherwiki', 'eg1'),
            'Second on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('otherwiki', 'eg2'),
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
                ['wikione_a_p', 'eg1.web.db.svc.eqiad.wmflabs'],
                // wikione_b: Use cached eg1 connection
                // wikione_c: Re-open eg1 connection after close
                ['wikione_c_p', 'eg1.web.db.svc.eqiad.wmflabs']
            )
            ->willReturn($pdo);

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('wikione_a', 'eg1'),
            'A on eg1'
        );

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('wikione_b', 'eg1'),
            'B on eg1'
        );

        $app->closeDB('eg1');

        $this->assertInstanceOf(
            PDO::class,
            $app->getDB('wikione_c', 'eg1'),
            'C on eg1'
        );
    }
}
