#! /usr/bin/env python3

import re, sys

pattern = '^(((?:\\*|[0-7])(,{1}|-{1}|\\/{1}|$))+)$'
# pattern = '^(((?:\\*|[0-7])(,\1|-\1|\/\1|$))+)$'
# pattern = "^((?:\*|[1-5][0-9]|0[0-9]|[0-9])(,\1|-\1|\/\1|$))+$"
# ^((?:\*|[1-5][0-9]|0[0-9]|[0-9])(,\1|-\1|\/\1|$))+$
# for arg in sys.argv[1:]:
#     arg = arg[1:-1]
#     if re.sub(pattern, "", arg):
#         print(arg)


print("foooo")

# re.compile(pattern)
# compile(f"r'{pattern}'", "schemas.json", "eval")

print(sorted(["abc", "kjgkjhgkjg", "d"], key=len))
