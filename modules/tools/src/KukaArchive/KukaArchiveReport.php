<?php

declare(strict_types=1);

namespace RC\Portal\Modules\Tools\KukaArchive;

defined('ABSPATH') || exit;

/**
 * Immutable result of analyzing one KUKA controller archive (.zip). Built
 * entirely in memory by KukaArchiveAnalyzer for the lifetime of a single
 * request — never serialized, cached or persisted anywhere (see the module's
 * "no persistence" requirement).
 *
 * Every list here is defensive: a section the archive doesn't contain, or
 * one KukaArchiveAnalyzer failed to parse, simply comes back empty rather
 * than aborting the whole analysis.
 */
final class KukaArchiveReport
{
    /**
     * @param string $sourceFileName original uploaded file name
     * @param int $sourceSize uploaded file size in bytes
     * @param int $entryCount number of entries in the zip archive
     * @param array<int,string> $warnings non-fatal issues encountered while parsing
     * @param array{name:?string,date:?string,id:?string,toolVersion:?string,robotName:?string,serialNumber:?string}|null $archive parsed am.ini
     * @param string|null $cellVersion `$V_STEUMADA` version tag (KRC/STEU/Mada/$machine.dat)
     * @param array<int,array{axis:string,numAxes:?int,madaVersion:?string,robcorVersion:?string,modelName:?string}> $robots one entry per KRC/R<n>
     * @param array<int,array{serialNumber:string,firstEncoderValues:array<int,int>,calibrationDifferences:array<int,float>,hasDrift:bool}> $calibrations
     * @param array<int,array{date:?\DateTimeImmutable,axis:int,serialNumber:string,label:string,firstEncoderValue:?int}> $masteringEvents
     * @param array<int,array{axis:int,serialNumbers:array<int,string>}> $masteringSerialChanges axes with more than one distinct serial number over time
     * @param array<int,array{reason:string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}> $warmStartErrors grouped by reason
     * @param array<int,array{task:?string,errorCode:?string,mode:?string,robotModel:?string,serialNumber:?string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}> $fatalErrors grouped tt.log entries
     * @param array<int,array{fileName:string,timestamp:?\DateTimeImmutable}> $debugDumps
     * @param array<int,array{robot:string,programs:array<int,string>}> $programs .src program names per robot
     * @param array<int,array{fileName:string,size:int,lastModified:?\DateTimeImmutable}> $unparsedDatabases Jet/Access message-log databases detected but not parsed (v1 scope limit)
     */
    public function __construct(
        public readonly string $sourceFileName,
        public readonly int $sourceSize,
        public readonly int $entryCount,
        public readonly \DateTimeImmutable $generatedAt,
        public readonly array $warnings,
        public readonly ?array $archive,
        public readonly ?string $cellVersion,
        public readonly array $robots,
        public readonly array $calibrations,
        public readonly array $masteringEvents,
        public readonly array $masteringSerialChanges,
        public readonly array $warmStartErrors,
        public readonly array $fatalErrors,
        public readonly array $debugDumps,
        public readonly array $programs,
        public readonly array $unparsedDatabases
    ) {
    }
}
