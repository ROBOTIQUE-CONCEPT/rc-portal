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
     * @param array<int,array{axis:string,numAxes:?int,madaVersion:?string,robcorVersion:?string,modelName:?string,serialNumber:?string}> $robots one entry per KRC/R<n>
     * @param array<int,array{name:string,version:string}> $techPacks installed KUKA TechPacks and their versions (am.ini `[TechPacks]`)
     * @param array<string,array<string,string>> $homePositions `XHOME`/`XHOME1..5` (E6AXIS) keyed by their variable name, each an axis(A1-A6/E1-E6)=>raw value map
     * @param array{items:array<int,array{index:int,name:?string,frame:array<string,string>}>,total:int} $bases `BASE_DATA`/`BASE_NAME` — unconfigured (blank name, all-zero frame) slots filtered out; `total` is the count actually declared in the file
     * @param array{items:array<int,array{index:int,name:?string,frame:array<string,string>}>,total:int} $tools `TOOL_DATA`/`TOOL_NAME`, same filtering as $bases
     * @param array{items:array<int,array{index:int,mass:float,centerOfMass:array<string,string>,inertia:array<string,string>}>,total:int} $loads `LOAD_DATA` — slots with `M=-1` (KUKA's own "unset" convention) filtered out
     * @param array<int,array{axis:int,mass:float,centerOfMass:array<string,string>,inertia:array<string,string>}> $additionalLoads `LOAD_A1_DATA`/`LOAD_A2_DATA`/`LOAD_A3_DATA`, same `M=-1` filtering
     * @param array{items:array<int,array{index:int,name:?string,mode:?string,params:array<string,string>}>,total:int} $workspaces `$WORKSPACE`/`$WORKSPACE_NAMEn` (KRC/STEU/Mada/$custom.dat) — envelopes with `MODE=#OFF` filtered out
     * @param array<int,array{name:string,target:string,targetTo:?string,comment:?string}> $signals `SIGNAL` declarations (KRC/R<n>/System/$config.dat)
     * @param array<int,array{serialNumber:string,firstEncoderValues:array<int,int>,calibrationDifferences:array<int,float>,hasDrift:bool}> $calibrations
     * @param array<int,array{date:?\DateTimeImmutable,axis:int,serialNumber:string,label:string,firstEncoderValue:?int}> $masteringEvents
     * @param array<int,array{axis:int,serialNumbers:array<int,string>}> $masteringSerialChanges axes with more than one distinct serial number over time
     * @param array<int,array{reason:string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}> $warmStartErrors grouped by reason
     * @param array<int,array{task:?string,errorCode:?string,mode:?string,robotModel:?string,serialNumber:?string,count:int,firstDate:?\DateTimeImmutable,lastDate:?\DateTimeImmutable}> $fatalErrors grouped tt.log entries
     * @param array<int,array{fileName:string,timestamp:?\DateTimeImmutable}> $debugDumps
     * @param array<int,array{robot:string,programs:array<int,string>}> $programs .src program names per robot
     * @param array{available:bool,databases:array<int,array{fileName:string,size:int,lastModified:?\DateTimeImmutable,parsed:bool,header:?array<string,string>,entries:array<int,array{category:string,date:?\DateTimeImmutable,source:?string,instance:?string,messageCode:?string,level:?string,module:?string,key:?string,class:?string,type:?string}>,error:?string}>} $messageLogs the Jet/Access message-log databases found in the archive; `available` says whether this server could attempt real parsing (mdbtools present) — when false, or when a given database's own `parsed` is false, only name/size/last-modified/`error` are populated for it
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
        public readonly array $techPacks,
        public readonly array $homePositions,
        public readonly array $bases,
        public readonly array $tools,
        public readonly array $loads,
        public readonly array $additionalLoads,
        public readonly array $workspaces,
        public readonly array $signals,
        public readonly array $calibrations,
        public readonly array $masteringEvents,
        public readonly array $masteringSerialChanges,
        public readonly array $warmStartErrors,
        public readonly array $fatalErrors,
        public readonly array $debugDumps,
        public readonly array $programs,
        public readonly array $messageLogs
    ) {
    }
}
