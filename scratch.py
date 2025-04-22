#! /usr/bin/env python3

import re, sys


alphabet = f"abcdefgheAJKLGH09673\t\n{chr(7)}😚🇦🇱"
# for char in alphabet:
#     print(char)

chars = []
for m in ("isalpha", "isalnum", "isascii", "isprintable"):
    method = getattr(str, m)
    chars = [char for char in alphabet if method(char)]
    if chars:
        break

if not chars:
    chars = alphabet.split()


chars = [char for char in alphabet if char.isascii()]
print(len(chars), chars)
