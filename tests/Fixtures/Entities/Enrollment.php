<?php

declare(strict_types=1);

namespace Darangonaut\DoctrineProjections\Tests\Fixtures\Entities;

use Doctrine\ORM\Mapping as ORM;

/** Composite primary key — Eloquent does not support one. */
#[ORM\Entity]
#[ORM\Table(name: 'enrollments')]
class Enrollment
{
    #[ORM\Id]
    #[ORM\Column(name: 'student_id', type: 'integer')]
    private int $studentId;

    #[ORM\Id]
    #[ORM\Column(name: 'course_id', type: 'integer')]
    private int $courseId;

    #[ORM\Column(name: 'grade', type: 'string', length: 2, nullable: true)]
    private ?string $grade = null;
}
