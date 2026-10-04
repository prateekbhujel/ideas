"""
Benchmark Contamination Partition.
Explicitly isolates games whose implementation/mechanics were inspected during debugging.
CLEAN_HOLDOUT_GAMES must NEVER be inspected, grepped, or parsed.
"""

CONTAMINATED_DEV_GAMES = [
    "ls20-9607627b",
    "bp35-0a0ad940",
    "vc33-5430563c",
    "ft09-0d8bbf25",
    "cn04-2fe56bfb",
]

CLEAN_HOLDOUT_GAMES = [
    "tn36-ef4dde99",
    "cd82-fb555c5d",
    "wa30-ee6fef47",
    "sk48-d8078629",
    "r11l-495a7899",
    "s5i5-18d95033",
    "lf52-271a04aa",
    "sb26-7fbdac44",
    "g50t-5849a774",
    "ka59-38d34dbb",
    "sp80-589a99af",
    "dc22-fdcac232",
    "lp85-305b61c3",
    "sc25-635fd71a",
    "tr87-cd924810",
    "m0r0-492f87ba",
    "su15-1944f8ab",
    "re86-8af5384d",
    "ar25-0c556536",
    "tu93-0768757b",
]

def is_contaminated(game_id: str) -> bool:
    gid = game_id.split("-")[0]
    return any(c.startswith(gid) for c in CONTAMINATED_DEV_GAMES)

def is_clean(game_id: str) -> bool:
    return not is_contaminated(game_id)
