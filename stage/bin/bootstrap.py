import os.path
import sys

base_dir = os.path.dirname(__file__)
bserv_home = os.path.dirname(base_dir)
os.environ["BSERV_HOME"] = bserv_home

core = os.path.join(bserv_home, 'lib', 'core')
sys.path.append(core)

docker = os.path.join(bserv_home, 'lib', 'docker')

if os.path.isdir(docker):
    print(docker)
    for f in os.listdir(docker):
        d = os.path.join(docker, f)
        sys.path.append(d)


from baykit.bayserver.bayserver import BayServer

BayServer.main(sys.argv)
